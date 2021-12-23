<?php

namespace App\GraphQL\Mutations;

use App\Enums\DraftConceptStatusTypeEnum;
use App\Exceptions\CustomException;
use App\Http\Traits\DraftTrait;
use App\Http\Traits\SignatureTrait;
use App\Models\Draft;
use App\Models\InboxFile;
use App\Models\InboxReceiver;
use App\Models\InboxReceiverCorrection;
use App\Models\People;
use App\Models\Signature;
use App\Models\TableSetting;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class DraftSignatureMutator
{
    use DraftTrait;
    use SignatureTrait;

    /**
     * @param  null  $_
     * @param  array<string, mixed>  $args
     */
    public function signature($rootValue, array $args)
    {
        $draftId    = Arr::get($args, 'input.draftId');
        $passphrase = Arr::get($args, 'input.passphrase');
        $draft      = Draft::where('NId_temp', $draftId)->first();

        if ($draft->Konsep == DraftConceptStatusTypeEnum::APPROVED()->value) {
            throw new CustomException('Document already signed', 'Status of this document is already signed');
        }

        $setupConfig = $this->setupConfigSignature();
        $checkUser = json_decode($this->checkUserSignature($setupConfig));
        if ($checkUser->status_code != 1111) {
            throw new CustomException('Invalid user', 'Invalid credential user, please check your passphrase again');
        }

        $signature = $this->doSignature($setupConfig, $draft, $passphrase);

        $draft->Konsep = DraftConceptStatusTypeEnum::APPROVED()->value;
        $draft->save();

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
        $verifyCode = substr(sha1(uniqid(mt_rand(), TRUE)), 0, 10);
        $response = Http::withHeaders([
            'Authorization' => 'Basic ' . $setupConfig['auth'],
            'Cookie' => 'JSESSIONID=' . $setupConfig['cookies'],
        ])->attach('file', $this->setDraftDocumentPdf($draft->NId_Temp, $verifyCode), $draft->document_file_name)->post($url, [
            'nik'           => $setupConfig['nik'],
            'passphrase'    => $passphrase,
            'tampilan'      => 'invisible',
            'page'          => '1',
            'image'         => 'false',
        ]);

        if ($response->status() != 200) {
            throw new CustomException('Document failed', 'Signature failed, check your file again');
        } else {
            //Save new file & update status
            $draft = $this->saveNewFile($response, $draft, $verifyCode);
            //Save log
            $this->createPassphraseSessionLog($response);
        }

        return $draft;
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
        //save signed data
        Storage::disk('local')->put($draft->document_file_name, $pdf->body());
        //transfer to existing service
        $response = $this->doTransferFile($draft);
        if ($response->status() != 200) {
            throw new CustomException('Webhook failed', json_decode($response));
        }
        $this->doSaveSignature($draft, $verifyCode);
        //remove temp data
        Storage::disk('local')->delete($draft->NId_Temp . '.png');
        Storage::disk('local')->delete($draft->document_file_name);

        return $draft;
    }

    /**
     * doTransferFile
     *
     * @param  collection $draft
     * @return mixed
     */
    public function doTransferFile($draft)
    {
        $fileSignatured = fopen(Storage::path($draft->document_file_name), 'r');
        $QrCode = fopen(Storage::path($draft->NId_Temp . '.png'), 'r');
        $response = Http::withHeaders([
            'Secret' => config('sikd.webhook_secret'),
        ])->attach('draft', $fileSignatured)->attach('qrcode', $QrCode)->post(config('sikd.webhook_url'));

        return $response;
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
        list($toId, $toRoleId, $toRoleDesc) = $this->getReceiverPeople($draft);

        $InboxReceiverCorrection = new InboxReceiverCorrection();
        $InboxReceiverCorrection->NId           = $draft->NId_Temp;
        $InboxReceiverCorrection->NKey          = TableSetting::first()->tb_key;
        $InboxReceiverCorrection->GIR_Id        = auth()->user()->PeopleId . Carbon::now();
        $InboxReceiverCorrection->From_Id       = auth()->user()->PeopleId;
        $InboxReceiverCorrection->RoleId_From   = auth()->user()->PrimaryRoleId;
        $InboxReceiverCorrection->To_Id         = $toId;
        $InboxReceiverCorrection->RoleId_To     = $toRoleId;
        $InboxReceiverCorrection->ReceiverAs    = 'approvenaskah';
        $InboxReceiverCorrection->StatusReceive = 'unread';
        $InboxReceiverCorrection->ReceiveDate   = Carbon::now();
        $InboxReceiverCorrection->To_Id_Desc    = $toRoleDesc;
        $InboxReceiverCorrection->save();

        return $InboxReceiverCorrection;
    }

    /**
     * getReceiverPeople
     *
     * @param  collection $draft
     * @return array
     */
    protected function getReceiverPeople($draft)
    {
        switch ($draft->TtdText) {
            case 'none':
                $toId = auth()->user()->PeopleId;
                $toRoleId = auth()->user()->PrimaryRoleId;
                $toRoleDesc = auth()->user()->role->RoleDesc;
                break;
            case 'AL':
                $parentId = People::where('PrimaryRoleId', auth()->user()->RoleAtasan)->first();
                $toId = $parentId->PeopleId;
                $toRoleId = $parentId->PrimaryRoleId;
                $toRoleDesc = $parentId->role->RoleDesc;
                break;
            default:
                $toId = $draft->Approve_People;
                $toRoleId = $draft->reviewer->PrimaryRoleId;
                $toRoleDesc = $draft->reviewer->role->RoleDesc;
                break;
        }

        return [$toId, $toRoleId, $toRoleDesc];
    }
}
