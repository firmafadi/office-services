<?php

namespace App\GraphQL\Queries;

use App\Enums\KafkaStatusTypeEnum;
use App\Enums\ObjectiveTypeEnum;
use App\Enums\SignatureStatusTypeEnum;
use App\Exceptions\CustomException;
use App\Http\Traits\KafkaTrait;
use App\Models\DocumentSignature;
use App\Models\DocumentSignatureSent;
use App\Models\DocumentSignatureSentRead;
use GraphQL\Type\Definition\ResolveInfo;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class DocumentSignatureQuery
{
    use KafkaTrait;

    /**
     * @param $rootValue
     * @param array                                                    $args
     * @param \Nuwave\Lighthouse\Support\Contracts\GraphQLContext|null $context
     *
     * @throws \Exception
     *
     * @return array
     */
    public function list($rootValue, array $args, GraphQLContext $context)
    {
        $data = DocumentSignatureSent::where('PeopleIDTujuan', auth()->user()->PeopleId)
                                    ->orderBy('tgl', 'DESC')
                                    ->get();

        if (!$data) {
            throw new CustomException(
                'Document not found',
                'Document with this user not found'
            );
        }

        $documentSignatureSent = [];
        foreach ($data as $_data) {
            if ($_data->urutan > 1) {
                $checkParent = DocumentSignatureSent::where('ttd_id', $_data->ttd_id)
                                                    ->where('urutan', $_data->urutan - 1)
                                                    ->first();
                if ($checkParent->status == 0) {
                    continue;
                }
            }

            array_push($documentSignatureSent, $_data);
        }

        return collect($documentSignatureSent);
    }

    /**
     * @param $rootValue
     * @param array                                                    $args
     * @param \Nuwave\Lighthouse\Support\Contracts\GraphQLContext|null $context
     *
     * @throws \Exception
     *
     * @return array
     */
    public function detail($rootValue, array $args, GraphQLContext $context)
    {
        $documentSignatureSent = DocumentSignatureSent::where('id', $args['id'])->first();
        $this->kafkaPublish('analytic_event', [
            'event' => 'read_letter',
            'status' => KafkaStatusTypeEnum::SUCCESS(),
            'origin' => 'document_signature',
            'letter' => [
                'inbox_id' => $args['id']
            ]
        ]);

        if (!$documentSignatureSent) {
            throw new CustomException(
                'Document not found',
                'Document with this id not found'
            );
        }

        //Check the inbox is readed or not
        $objective = (!empty($args['objective'])) ? $args['objective'] : ObjectiveTypeEnum::IN(); //handle for old mobile app
        if ($objective == ObjectiveTypeEnum::IN()) { // action from inbox
            if ($documentSignatureSent->PeopleIDTujuan == auth()->user()->PeopleId) {
                $documentSignatureSent->is_receiver_read = true;
            }

            if ($documentSignatureSent->PeopleID == auth()->user()->PeopleId) {
                $documentSignatureSent->is_sender_read = true;
                if (
                    $documentSignatureSent->PeopleID == $documentSignatureSent->forward_receiver_id &&
                    $documentSignatureSent->status == SignatureStatusTypeEnum::SUCCESS()->value
                ) {
                    DocumentSignature::where('id', $documentSignatureSent->ttd_id)
                                     ->update(['is_conceptor_read' => true]);
                }
            }

            $documentSignatureSent->save();
        }

        return $documentSignatureSent;
    }

    /**
     * @param $rootValue
     * @param array                                                    $args
     * @param \Nuwave\Lighthouse\Support\Contracts\GraphQLContext|null $context
     *
     * @throws \Exception
     *
     * @return array
     */
    public function timelines($rootValue, array $args, GraphQLContext $context)
    {
        $documentSignatureIds   = explode(", ", $args['filter']['documentSignatureIds']);
        $sorts                  = explode(", ", $args['filter']['sorts']);
        $status                 = $args['filter']['status'] ?? null;

        $argsGroup = array_combine($documentSignatureIds, $sorts);

        $items = [];
        foreach ($argsGroup as $documentSignatureId => $sort) {
            $documentSignature = $this->doQueryTimelines($documentSignatureId, $sort, $status);

            array_push($items, [
                'documentSignatuerSents' => $documentSignature
            ]);
        }

        return $items;
    }

    /**
     * doQueryTimelines
     *
     * @param  integer $documentSignatureId
     * @param  integer $sort
     * @param  enum $status
     * @return collection
     */
    protected function doQueryTimelines($documentSignatureId, $sort, $status)
    {
        $documentSignature = DocumentSignatureSent::where('ttd_id', $documentSignatureId)
                                                        ->where('urutan', '<', $sort);

        if ($status) {
            if ($status == SignatureStatusTypeEnum::SIGNED()) {
                $documentSignature->where('status', SignatureStatusTypeEnum::SUCCESS()->value);
            }
            if ($status == SignatureStatusTypeEnum::UNSIGNED()) {
                $documentSignature->whereIn(
                    'status',
                    [
                        SignatureStatusTypeEnum::WAITING()->value,
                        SignatureStatusTypeEnum::REJECT()->value
                    ]
                );
            }
        }

        $documentSignature = $documentSignature->orderBy('urutan', 'DESC')->get();

        return $documentSignature;
    }
}
