<?php

namespace App\GraphQL\Mutations;

use App\Enums\ActionLabelTypeEnum;
use App\Enums\BsreStatusTypeEnum;
use App\Enums\DraftConceptStatusTypeEnum;
use App\Enums\FcmNotificationActionTypeEnum;
use App\Enums\FcmNotificationListTypeEnum;
use App\Enums\InboxReceiverCorrectionTypeEnum;
use App\Enums\KafkaStatusTypeEnum;
use App\Enums\PeopleGroupTypeEnum;
use App\Enums\PeopleIsActiveEnum;
use App\Enums\SignatureDocumentTypeEnum;
use App\Enums\SignatureMethodTypeEnum;
use App\Exceptions\CustomException;
use App\Http\Traits\DraftTrait;
use App\Http\Traits\KafkaTrait;
use App\Http\Traits\SendNotificationTrait;
use App\Http\Traits\SignatureTrait;
use App\Models\Draft;
use App\Models\Inbox;
use App\Models\InboxFile;
use App\Models\InboxReceiver;
use App\Models\InboxReceiverCorrection;
use App\Models\People;
use App\Models\Signature;
use App\Models\TableSetting;
use Carbon\Carbon;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class DraftSignatureMutator
{
    use DraftTrait;
    use SendNotificationTrait;
    use SignatureTrait;
    use KafkaTrait;

    /**
     * @param  null  $_
     * @param  array<string, mixed>  $args
     */
    public function signature($rootValue, array $args)
    {
        $draftId    = Arr::get($args, 'input.draftId');
        $passphrase = Arr::get($args, 'input.passphrase');
        $draftHistory = InboxReceiverCorrection::where('NId', $draftId)
                        ->where('ReceiverAs', InboxReceiverCorrectionTypeEnum::SIGNED()->value)->first();

        if ($draftHistory) {
            throw new CustomException('Dokumen telah ditandatangan', 'Dokumen ini telah ditandatangani oleh Anda');
        }

        $draft       = Draft::where('NId_temp', $draftId)->first();
        $setupConfig = $this->setupConfigSignature();
        $signature = $this->doSignature($setupConfig, $draft, $passphrase);

        $draft->Konsep = DraftConceptStatusTypeEnum::SENT()->value;
        $draft->save();

        $this->kafkaPublish('analytic_event', [
            'event' => 'esign_sign_draft',
            'status' => KafkaStatusTypeEnum::ESIGN_SUCCESS(),
            'letter' => [
                'id' => $draftId
            ]
        ]);

        return $signature;
    }

    /**
     * doSignature
     *
     * @param  array $setupConfig
     * @param  collection $draft
     * @param  string $passphrase
     * @return collection
     */
    protected function doSignature($setupConfig, $draft, $passphrase)
    {
        $url = $setupConfig['url'] . '/api/sign/pdf';
        $verifyCode = strtoupper(substr(sha1(uniqid(mt_rand(), true)), 0, 10));
        $tmpFileFooterName = 'FOOTER_' . $draft->document_file_name;
        $pdfFile = $this->addFooterDocument($draft, $verifyCode);

        Storage::disk('local')->put($tmpFileFooterName, $pdfFile);
        $pdfFile = file_get_contents(Storage::path($tmpFileFooterName), 'r');

        $response = Http::withHeaders([
            'Authorization' => 'Basic ' . $setupConfig['auth'],
            'Cookie' => 'JSESSIONID=' . $setupConfig['cookies'],
        ])->attach('file', $pdfFile, $draft->document_file_name)->post($url, [
            'nik'           => $setupConfig['nik'],
            'passphrase'    => $passphrase,
            'tampilan'      => 'invisible',
            'image'         => 'false',
        ]);

        Storage::disk('local')->delete($tmpFileFooterName);

        if ($response->status() != Response::HTTP_OK) {
            $bodyResponse = json_decode($response->body());
            $this->setPassphraseSessionLog($response, $draft, SignatureDocumentTypeEnum::DRAFTING_DOCUMENT());
            throw new CustomException('Gagal melakukan tanda tangan elektronik', $bodyResponse->error);
        } else {
            //Save new file & update status
            $draft = $this->saveNewFile($response, $draft, $verifyCode);
            //Save log
            $this->setPassphraseSessionLog($response, $draft, SignatureDocumentTypeEnum::DRAFTING_DOCUMENT());
            return $draft;
        }
    }

    /**
     * addFooterDocument
     *
     * @param  mixed $draft
     * @param  string $verifyCode
     * @return void
     */
    protected function addFooterDocument($draft, $verifyCode)
    {
        try {
            $addFooter = Http::attach(
                'pdf',
                file_get_contents($draft->draft_file . '?esign=true'),
                $draft->document_file_name
            )->post(config('sikd.add_footer_url'), [
                'qrcode' => config('sikd.url') . 'verification/document/tte/' . $verifyCode . '?source=qrcode',
                'category' => $draft->category_footer,
                'code' => $verifyCode
            ]);

            return $addFooter;
        } catch (\Throwable $th) {
            $logData = [
                'event' => 'esign_FOOTER_DRAFT_pdf',
                'status' => KafkaStatusTypeEnum::ESIGN_FOOTER_FAILED_UNKNOWN(),
                'esign_source_file' => $draft->draft_file . '?esign=true',
                'esign_response' => $th,
                'message' => 'Gagal menambahkan QRCode dan text footer',
                'longMessage' => 'Gagal menambahkan QRCode dan text footer kedalam PDF, silahkan coba kembali'
            ];

            $this->kafkaPublish('analytic_event', $logData);
            throw new CustomException($logData['message'], $logData['longMessage']);
        }
    }

    /**
     * saveNewFile
     *
     * @param  mixed $pdf
     * @param  collection $draft
     * @param  string $verifyCode
     * @return collection
     */
    protected function saveNewFile($pdf, $draft, $verifyCode)
    {
        ///save signed data
        Storage::disk('local')->put($draft->document_file_name, $pdf->body());

        $response = $this->doTransferFile($draft);
        if ($response->status() != Response::HTTP_OK) {
            $logData = $this->logInvalidTransferFile('esign_transfer_draft_pdf', $draft->document_file_name, $response);
            $this->kafkaPublish('analytic_event', $logData);
            throw new CustomException($logData['message'], $logData['longMessage']);
        } else {
            $this->doSaveSignature($draft, $verifyCode);
            //remove temp data
            Storage::disk('local')->delete($draft->document_file_name);
            return $draft;
        }
    }

    /**
     * doTransferFile
     *
     * @param  collection $draft
     * @return mixed
     */
    public function doTransferFile($draft)
    {
        try {
            $fileSignatured = fopen(Storage::path($draft->document_file_name), 'r');
            $response = Http::withHeaders([
                'Secret' => config('sikd.webhook_secret'),
            ])->attach('draft', $fileSignatured)->post(config('sikd.webhook_url'));

            return $response;
        } catch (\Throwable $th) {
            $logData = $this->logInvalidConnectTransferFile('esign_transfer_draft_pdf', $draft->document_file_name, $th);
            $this->kafkaPublish('analytic_event', $logData);
            throw new CustomException($logData['message'], $logData['longMessage']);
        }
    }

    /**
     * doSaveSignature
     *
     * @param  mixed $draft
     * @param  mixed $verifyCode
     * @return mixed
     */
    protected function doSaveSignature($draft, $verifyCode)
    {
        DB::beginTransaction();
        try {
            $signature = $this->updateDataDraftAfterEsign($draft, $verifyCode);

            DB::commit();
            return $signature;
        } catch (\Throwable $th) {
            DB::rollBack();
            $logData = [
                'event' => 'esign_update_status_draft_pdf',
                'status' => KafkaStatusTypeEnum::ESIGN_INVALID_UPDATE_STATUS_AND_DATA(),
                'esign_source_file' => $draft->document_file_name,
                'response' => $th,
                'message' => 'Gagal menyimpan perubahan data',
                'longMessage' => $th->getMessage()
            ];
            // Set return failure esign
            throw new CustomException($logData['message'], $logData['longMessage']);
        }
    }

    /**
     * updateDataDraftAfterEsign
     *
     * @param  mixed $draft
     * @param  mixed $verifyCode
     * @return void
     */
    protected function updateDataDraftAfterEsign($draft, $verifyCode)
    {
        $signature = new Signature();
        $signature->NId    = $draft->NId_Temp;
        $signature->TglProses   = Carbon::now();
        $signature->PeopleId    = auth()->user()->PeopleId;
        $signature->RoleId      = auth()->user()->PrimaryRoleId;
        $signature->Verifikasi  = $verifyCode;
        $signature->QRCode      = $draft->NId_Temp . '.png';
        $signature->save();

        $this->doSaveInboxFile($draft, $verifyCode);
        $this->doUpdateInboxReceiver($draft);
        $this->doSaveInboxReceiverCorrection($draft);
        $this->doUpdateInboxReceiverCorrection($draft);
        //Forward the document to TU / UK
        $this->forwardToInbox($draft);
        $draftReceiverAsToTarget = config('constants.draftReceiverAsToTarget');
        $this->forwardToInboxReceiver($draft, $draftReceiverAsToTarget);
        if (!in_array($draft->Ket, array_keys($draftReceiverAsToTarget))) {
            $this->forwardSaveInboxReceiverCorrection($draft, $draftReceiverAsToTarget);
        }

        return $signature;
    }

    /**
     * doSaveInboxFile
     *
     * @param  mixed $draft
     * @param  mixed $verifyCode
     * @return void
     */
    protected function doSaveInboxFile($draft, $verifyCode)
    {
        $inboxFile = new InboxFile();
        $inboxFile->FileKey         = TableSetting::first()->tb_key;
        $inboxFile->GIR_Id          = $draft->GIR_Id;
        $inboxFile->NId             = $draft->NId_Temp;
        $inboxFile->PeopleID        = auth()->user()->PeopleId;
        $inboxFile->PeopleRoleID    = auth()->user()->PrimaryRoleId;
        $inboxFile->FileName_real   = $draft->document_file_name;
        $inboxFile->FileName_fake   = $draft->document_file_name;
        $inboxFile->FileStatus      = 'available';
        $inboxFile->EditedDate      = Carbon::now();
        $inboxFile->Keterangan      = 'outbox';
        $inboxFile->Id_Dokumen      = $verifyCode;
        $inboxFile->save();

        return $inboxFile;
    }

    /**
     * doUpdateInboxReceiver
     *
     * @param  mixed $draft
     * @return void
     */
    protected function doUpdateInboxReceiver($draft)
    {
        $InboxReceiver = InboxReceiver::where('NId', $draft->NId_Temp)
                                        ->where('RoleId_To', auth()->user()->RoleId)
                                        ->update(['Status' => 1,'StatusReceive' => 'read']);

        $this->kafkaPublish('analytic_event', [
            'event' => 'read_letter',
            'status' => KafkaStatusTypeEnum::SUCCESS(),
            'letter' => [
                'inbox_id' => $draft->NId_Temp
            ]
        ]);
        return $InboxReceiver;
    }

    /**
     * doSaveInboxReceiverCorrection
     *
     * @param  mixed $draft
     * @return void
     */
    protected function doSaveInboxReceiverCorrection($draft)
    {
        $InboxReceiverCorrection = new InboxReceiverCorrection();
        $InboxReceiverCorrection->NId           = $draft->NId_Temp;
        $InboxReceiverCorrection->NKey          = TableSetting::first()->tb_key;
        $InboxReceiverCorrection->GIR_Id        = auth()->user()->PeopleId . Carbon::now();
        $InboxReceiverCorrection->From_Id       = auth()->user()->PeopleId;
        $InboxReceiverCorrection->RoleId_From   = auth()->user()->PrimaryRoleId;
        $InboxReceiverCorrection->To_Id         = ($draft->TtdText == 'none') ? auth()->user()->PeopleId : null;
        $InboxReceiverCorrection->RoleId_To     = ($draft->TtdText == 'none') ? auth()->user()->PrimaryRoleId : null;
        $InboxReceiverCorrection->ReceiverAs    = 'approvenaskah';
        $InboxReceiverCorrection->StatusReceive = 'unread';
        $InboxReceiverCorrection->ReceiveDate   = Carbon::now();
        $InboxReceiverCorrection->To_Id_Desc    = ($draft->TtdText == 'none') ? auth()->user()->RoleDesc : null;
        $InboxReceiverCorrection->action_label  = ActionLabelTypeEnum::APPROVED();
        $InboxReceiverCorrection->save();

        return $InboxReceiverCorrection;
    }

    /**
     * doUpdateInboxReceiverCorrection
     *
     * @param  mixed $draft
     * @return void
     */
    protected function doUpdateInboxReceiverCorrection($draft)
    {
        $draftId = $draft->NId_Temp;
        $userRoleId = auth()->user()->PrimaryRoleId;
        InboxReceiverCorrection::where('NId', $draftId)
            ->where('RoleId_To', $userRoleId)
            ->update(['action_label' => ActionLabelTypeEnum::SIGNED()]);
    }

    /**
     * forwardToInbox
     *
     * @param  mixed $draft
     * @return void
     */
    protected function forwardToInbox($draft)
    {
        $inbox = new Inbox();
        $inbox->NKey            = TableSetting::first()->tb_key;
        $inbox->NId             = $draft->NId_Temp;
        $inbox->CreatedBy       = auth()->user()->PeopleId;
        $inbox->CreationRoleId  = auth()->user()->PrimaryRoleId;
        $inbox->NTglReg         = Carbon::now();
        $inbox->Tgl             = Carbon::parse($draft->TglNaskah)->format('Y-m-d H:i:s');
        $inbox->JenisId         = $draft->JenisId;
        $inbox->UrgensiId       = $draft->UrgensiId;
        $inbox->SifatId         = $draft->SifatId;
        $inbox->Nomor           = $draft->nosurat;
        $inbox->Hal             = $draft->Hal;
        $inbox->Pengirim        = 'internal';
        $inbox->NTipe           = $draft->Ket;
        $inbox->Namapengirim    = auth()->user()->role->RoleDesc;
        $inbox->NFileDir        = 'naskah';
        $inbox->BerkasId        = '1';
        $inbox->save();

        return $inbox;
    }

    /**
     * forwardToInboxReceiver
     *
     * @param  mixed $draft
     * @return void
     */

    protected function forwardToInboxReceiver($draft, $draftReceiverAsToTarget)
    {
        $receiver = $this->getTargetInboxReceiver($draft, $draftReceiverAsToTarget);
        $labelReceiverAs = (in_array($draft->Ket, array_keys($draftReceiverAsToTarget))) ? $draftReceiverAsToTarget[$draft->Ket] : 'to_forward';
        $groupId = auth()->user()->PeopleId . Carbon::now();
        $allReceiverIds = $this->doForwardToInboxReceiver($draft, $receiver, $labelReceiverAs, $groupId);

        if ($draft->RoleId_Cc != null && in_array($draft->Ket, array_keys($draftReceiverAsToTarget))) {
            $peopleCCIds = People::whereIn('PrimaryRoleId', explode(',', $draft->RoleId_Cc))
                            ->where('PeopleIsActive', PeopleIsActiveEnum::ACTIVE()->value)
                            ->where('GroupId', '!=', PeopleGroupTypeEnum::TU()->value)
                            ->get();
            $sendToTargetCC = $this->doForwardToInboxReceiver($draft, $peopleCCIds, 'bcc', $groupId);
            $allReceiverIds = array_merge($allReceiverIds, $sendToTargetCC);
        }

        if (!empty($allReceiverIds)) {
            $this->doSendForwardNotification($draft, $groupId, $allReceiverIds);
        }

        return $receiver;
    }

    /**
     * doForwardToInboxReceiver
     *
     * @param  mixed $draft
     * @param  mixed $receiver
     * @param  string $receiverAs
     * @param  string $groupId
     * @return void
     */
    protected function doForwardToInboxReceiver($draft, $receiver, $receiverAs, $groupId)
    {
        $receiverIds = [];
        foreach ($receiver as $key => $value) {
            // get people id for send the notification if draft direct send to target (NOT to_forward status = to UK)
            if ($receiverAs != 'to_forward') {
                array_push($receiverIds, $value->PeopleId);
            }

            $InboxReceiver = new InboxReceiver();
            $InboxReceiver->NId           = $draft->NId_Temp;
            $InboxReceiver->NKey          = TableSetting::first()->tb_key;
            $InboxReceiver->GIR_Id        = $groupId;
            $InboxReceiver->From_Id       = auth()->user()->PeopleId;
            $InboxReceiver->RoleId_From   = auth()->user()->PrimaryRoleId;
            $InboxReceiver->To_Id         = $value->PeopleId;
            $InboxReceiver->RoleId_To     = $value->PrimaryRoleId;
            $InboxReceiver->ReceiverAs    = $receiverAs;
            $InboxReceiver->StatusReceive = 'unread';
            $InboxReceiver->ReceiveDate   = Carbon::now();
            $InboxReceiver->To_Id_Desc    = $value->role->RoleDesc;
            $InboxReceiver->Status        = '0';
            $InboxReceiver->action_label  = ActionLabelTypeEnum::REVIEW();
            $InboxReceiver->save();
        }

        return $receiverIds;
    }

    /**
     * getTargetInboxReceiver
     *
     * @param  mixed $draft
     * @param  array $draftReceiverAsToTarget
     * @return array
     */

    protected function getTargetInboxReceiver($draft, $draftReceiverAsToTarget)
    {
        if (in_array($draft->Ket, array_keys($draftReceiverAsToTarget))) {
            $peopleIds = People::whereIn('PeopleId', explode(',', $draft->RoleId_To))
                        ->where('PeopleIsActive', PeopleIsActiveEnum::ACTIVE()->value)
                        ->get();
        } else {
            $peopleIds = People::whereHas('role', function ($role) {
                $role->where('RoleCode', auth()->user()->role->RoleCode);
                $role->where('GRoleId', auth()->user()->role->GRoleId);
            })->where('GroupId', PeopleGroupTypeEnum::UK()->value)
            ->where('PeopleIsActive', PeopleIsActiveEnum::ACTIVE()->value)
            ->get();
        }

        return $peopleIds;
    }

    /**
     * forwardSaveInboxReceiverCorrection
     *
     * @param  mixed $draft
     * @param  array $draftReceiverAsToTarget
     * @return void
     */
    protected function forwardSaveInboxReceiverCorrection($draft, $draftReceiverAsToTarget)
    {
        $receiver = $this->getTargetInboxReceiver($draft, $draftReceiverAsToTarget);
        foreach ($receiver as $key => $value) {
            $InboxReceiverCorrection = new InboxReceiverCorrection();
            $InboxReceiverCorrection->NId           = $draft->NId_Temp;
            $InboxReceiverCorrection->NKey          = TableSetting::first()->tb_key;
            $InboxReceiverCorrection->GIR_Id        = auth()->user()->PeopleId . Carbon::now()->addSeconds(1);
            $InboxReceiverCorrection->From_Id       = auth()->user()->PeopleId;
            $InboxReceiverCorrection->RoleId_From   = auth()->user()->PrimaryRoleId;
            $InboxReceiverCorrection->To_Id         = $value->PeopleId;
            $InboxReceiverCorrection->RoleId_To     = $value->PrimaryRoleId;
            $InboxReceiverCorrection->ReceiverAs    = 'meneruskan';
            $InboxReceiverCorrection->StatusReceive = 'unread';
            $InboxReceiverCorrection->ReceiveDate   = Carbon::now()->addSeconds(1);
            $InboxReceiverCorrection->To_Id_Desc    = $value->role->RoleDesc;
            $InboxReceiverCorrection->action_label  = ActionLabelTypeEnum::REVIEW();
            $InboxReceiverCorrection->save();
        }
    }

    /**
     * doSendForwardNotification
     *
     * @param  mixed $draft
     * @param  string $groupId
     * @param  array $receiverIds
     * @return void
     */
    protected function doSendForwardNotification($draft, $groupId, $receiverIds)
    {
        $people = auth()->user()->PeopleName;
        $draftType = $draft->draftType->JenisName;
        $draftTitle = $draft->Hal;
        // set group id value
        $peopleId = substr($groupId, 0, -19);
        $dateString = substr($groupId, -19);
        $date = parseDateTimeFormat($dateString, 'dmyhis');
        $groupId = $peopleId . $date;

        $body = $people . ' telah mengirimkan ' . $draftType . ' terkait dengan ' . $draftTitle . '. Klik disini untuk membaca dan menindaklanjuti pesan.';
        $messageAttribute = [
            'notification' => [
                'title' => '',
                'body' => str_replace('&nbsp;', ' ', strip_tags($body))
            ],
            'data' => [
                'inboxId' => $draft->NId_Temp,
                'groupId' => $groupId,
                'peopleIds' => $receiverIds,
                'action' => FcmNotificationActionTypeEnum::INBOX_DETAIL(),
                'list' => FcmNotificationListTypeEnum::DRAFT_INSIDE()
            ]
        ];

        $this->setupInboxReceiverNotification($messageAttribute);
    }
}
