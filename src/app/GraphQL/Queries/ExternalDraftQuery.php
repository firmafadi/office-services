<?php

namespace App\GraphQL\Queries;

use App\Enums\KafkaStatusTypeEnum;
use App\Enums\StatusReadTypeEnum;
use App\Exceptions\CustomException;
use App\Http\Traits\KafkaTrait;
use App\Models\DocumentSignatureForward;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class ExternalDraftQuery
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
    public function detail($rootValue, array $args, GraphQLContext $context)
    {
        $externalDraft = DocumentSignatureForward::find($args['id']);
        if (!$externalDraft) {
            throw new CustomException(
                'External draft not found',
                'Id is not found'
            );
        }
        $externalDraft->is_read = StatusReadTypeEnum::READ()->value;
        $externalDraft->save();

        $this->kafkaPublish('analytic_event', [
            'event' => 'read_register_letter',
            'status' => KafkaStatusTypeEnum::SUCCESS(),
            'letter' => [
                'id' => $args['id']
            ]
        ]);

        return $externalDraft;
    }
}
