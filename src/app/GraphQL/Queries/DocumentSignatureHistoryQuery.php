<?php

namespace App\GraphQL\Queries;

use App\Enums\DocumentSignatureTypeDistributeForwardEnum;
use App\Enums\SignatureStatusTypeEnum;
use App\Exceptions\CustomException;
use App\Models\DocumentSignature;
use App\Models\DocumentSignatureForward;
use App\Models\DocumentSignatureSent;
use App\Models\InboxReceiver;
use App\Models\InboxReceiverTemp;
use App\Models\InboxTemp;
use GraphQL\Type\Definition\ResolveInfo;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class DocumentSignatureHistoryQuery
{
    /**
     * @param $rootValue
     * @param array                                                    $args
     * @param \Nuwave\Lighthouse\Support\Contracts\GraphQLContext|null $context
     *
     * @throws \Exception
     *
     * @return array
     */
    public function history($rootValue, array $args, GraphQLContext $context)
    {
        $documentSignatureSent = $this->documentSignatureSent($args);
        $documentSignatureForward = $this->documentSignatureForward($args);
        $documentSignature = DocumentSignature::where('id', $args['documentSignatureId'])->first();

        $signedSelf = null;
        if ($documentSignature && $documentSignature->is_signed_self) {
            $signedSelf = $documentSignature;
        }

        $inboxId = null;
        if (count($documentSignatureSent) == 0) {
            if ($documentSignature) {
                $inboxId = optional($documentSignature->inboxFile)->NId;
            }
        } else {
            //select one document signature sent, get name file for relation to inbox file
            $inboxId = optional($documentSignatureSent->first()->documentSignature->inboxFile)->NId;
        }

        list($distributed, $documentSignatureDistribute) = $this->documentSignatureDistribute($inboxId, $documentSignature);
        $readDistributed = $this->readDistributed($documentSignature);

        $data = collect([
            'documentSignatureDistribute' => [
                'data' => $documentSignatureDistribute,
                'distributed' => $distributed,
                'readDistributed' => $readDistributed,
                'typeDistributed' => $documentSignature->documentSignatureType->document_distribution_target
            ],
            'documentSignatureForward' => $documentSignatureForward,
            'documentSignatureSent' => $documentSignatureSent,
            'documentSignatureSelf' => $signedSelf,
        ]);

        return $data;
    }

    /**
     * documentSignatureSent
     *
     * @param  mixed $args
     * @return object
     */
    protected function documentSignatureSent($args)
    {
        $documentSignatureSent = DocumentSignatureSent::where('ttd_id', $args['documentSignatureId'])
                                    ->with(['sender', 'receiver'])
                                    ->orderBy('urutan', 'ASC')
                                    ->get();

        return $documentSignatureSent;
    }

    /**
     * documentSignatureForward
     *
     * @param  mixed $args
     * @return object
     */
    protected function documentSignatureForward($args)
    {
        $documentSignatureForward = DocumentSignatureSent::where('ttd_id', $args['documentSignatureId'])
                                    ->with(['sender', 'receiver'])
                                    ->orderBy('urutan', 'DESC')
                                    ->first();

        if ($documentSignatureForward && $documentSignatureForward->status == SignatureStatusTypeEnum::SUCCESS()->value) {
            return $documentSignatureForward;
        }

        return null;
    }

    /**
     * documentSignatureDistribute
     *
     * @param  mixed $inboxId
     * @param  mixed $documentSignature
     * @return array
     */
    protected function documentSignatureDistribute($inboxId, $documentSignature)
    {
        $documentSignatureDistribute = [];
        $distributed = false;
        // Data distributed (available on inbox files table)
        $documentSignatureTypeDistributeTarget = $documentSignature->documentSignatureType->document_distribution_target;
        $status = ($documentSignatureTypeDistributeTarget == DocumentSignatureTypeDistributeForwardEnum::TU()) ? 'to' : 'to_distributed';
        if ($inboxId) {
            $distributed = true;
            $documentSignatureDistribute = InboxReceiver::where('NId', $inboxId)
                                        ->with(['sender', 'receiver'])
                                        ->where('ReceiverAs', $status)
                                        ->get();
        } else {
            // Find data is registered or not
            $inboxTemp = InboxTemp::where('ttd_id', $documentSignature->id)->first();
            if ($inboxTemp) {
                $documentSignatureDistribute = InboxReceiverTemp::where('NId', $inboxTemp->NId)
                                            ->with(['sender', 'receiver'])
                                            ->where('ReceiverAs', 'to_distributed')
                                            ->get();
            }
        }

        return [$distributed, $documentSignatureDistribute];
    }

    /**
     * readDistributed
     *
     * @param  mixed $documentSignature
     * @return void
     */
    protected function readDistributed($documentSignature)
    {
        $documentSignatureForward = DocumentSignatureForward::where('ttd_id', $documentSignature->id)
                                    ->first();

        if (!$documentSignatureForward) {
            return false;
        }

        return $documentSignatureForward->is_read;
    }
}
