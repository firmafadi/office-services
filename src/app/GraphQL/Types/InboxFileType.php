<?php

namespace App\GraphQL\Types;

use App\Enums\InboxReceiverCorrectionTypeEnum;
use App\Enums\SignatureStatusTypeEnum;
use App\Models\DocumentSignature;
use App\Models\People;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class InboxFileType
{
    /**
     * @param $rootValue
     * @param array                                                    $args
     * @param \Nuwave\Lighthouse\Support\Contracts\GraphQLContext|null $context
     *
     * @return array
     */
    public function validate($rootValue, array $args, GraphQLContext $context)
    {
        $fileName = $rootValue->FileName_fake;

        $signatures = $this->getSignatures($fileName);
        $signaturesResponse = json_decode($signatures);
        if ($signatures->status() != Response::HTTP_OK || property_exists($signaturesResponse, 'error') || $signaturesResponse->jumlah_signature == 0) {
            return [
                'isValid' => false,
                'signatures' => null
            ];
        };

        $signers = $this->getSigners($rootValue);

        $validation = [
            'isValid' => true,
            'signatures' => $signers
        ];

        return $validation;
    }

    /**
     * @param String $fileName
     *
     * @return Mixed
     */
    protected function getSignatures($fileName)
    {
        $filePath   = config('sikd.base_path_file_letter') . $fileName;
        $file       = fopen($filePath, 'r');

        $response = Http::withHeaders([
            'Accept' => '*/*',
            'Authorization' => 'Basic ' . config('sikd.signature_auth'),
        ])->attach(
            'signed_file',
            $file,
            $fileName
        )->post(config('sikd.signature_verify_url'));

        return $response;
    }

    /**
     * @param Object $data
     *
     * @return Array
     */
    protected function getSigners($data)
    {
        $signers = People::whereIn('PeopleId', function ($inboxReceiverCorrection) use ($data) {
                        $inboxReceiverCorrection->select('From_Id')
                                                ->from('inbox_receiver_koreksi')
                                                ->where('Nid', $data->NId)
                                                ->where('ReceiverAs', InboxReceiverCorrectionTypeEnum::SIGNED()->value);
        })->get();

        if ($signers->isEmpty()) {
            $documentSignature = DocumentSignature::where('file', $data->FileName_fake)
                                                ->orWhere('file', $data->FileName_real)
                                                ->first();
            $signers = People::whereIn('PeopleId', function ($query) use ($documentSignature) {
                $query->select('PeopleIDTujuan')
                    ->from('m_ttd_kirim')
                    ->where('status', SignatureStatusTypeEnum::SUCCESS()->value)
                    ->where('ttd_id', $documentSignature->id)
                    ->whereIn('PeopleIDTujuan', $documentSignature->documentSignatureSents->pluck('PeopleIDTujuan'));
            })->get();

            if ($documentSignature->is_signed_self == true) {
                $signers = $this->addSelfSignature($documentSignature, $signers);
            }
        }

        return $signers;
    }

    /**
     * addSelfSignature
     *
     * @param  mixed $documentSignature
     * @param  mixed $signers
     * @return void
     */
    protected function addSelfSignature($documentSignature, $signers)
    {
        $selfSigned = People::where('PeopleId', $documentSignature->PeopleID)->get();
        if (count($signers) > 0) {
            $signers = $signers->merge($selfSigned);
        } else {
            $signers = $selfSigned;
        }

        return $signers;
    }
}
