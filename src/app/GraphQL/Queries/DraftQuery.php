<?php

namespace App\GraphQL\Queries;

use App\Enums\KafkaStatusTypeEnum;
use App\Enums\PeopleGroupTypeEnum;
use App\Exceptions\CustomException;
use App\Http\Traits\KafkaTrait;
use App\Models\Draft;
use App\Models\InboxReceiverCorrection;
use App\Models\People;
use Illuminate\Support\Arr;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class DraftQuery
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
        $inboxReceiverCorrection = InboxReceiverCorrection::where('NId', $args['draftId'])
                                                        ->where('GIR_Id', $args['groupId'])
                                                        ->first();

        if (!$inboxReceiverCorrection) {
            throw new CustomException(
                'Draft not found',
                'Draft with this NId not found'
            );
        }

        $inboxReceiverCorrection->StatusReceive = 'read';
        $inboxReceiverCorrection->save();

        $this->kafkaPublish('analytic_event', [
            'event' => 'read_letter',
            'status' => KafkaStatusTypeEnum::SUCCESS(),
            'letter' => [
                'inbox_id' => $inboxReceiverCorrection->NId
            ]
        ]);

        return $inboxReceiverCorrection;
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
    public function timeline($rootValue, array $args, GraphQLContext $context)
    {
        $draftId = Arr::get($args, 'filter.draftId');
        $type = Arr::get($args, 'filter.type');

        $inboxReceiverCorrection = new InboxReceiverCorrection();
        $receiverAsReviewData = $inboxReceiverCorrection->getReceiverAsReviewData();

        $draft = Draft::where('NId_temp', $draftId)->first();
        $hasNumber = $draft->nosurat ? true : false;
        $receiverAsReviewData = Arr::prepend($receiverAsReviewData, $type);
        $inboxReceiverCorrections = $this->inboxReceiverCorrection($draftId, $receiverAsReviewData);

        // check draft have letter number (already sent to uk/tu) then remove latest receiver when user only signature (not review the draft)
        if ($hasNumber) {
            // we need to recheck to same query because we don't have a flag for know a receiver from forward function is review or just sign the document
            $inboxReceiverCorrectionsForCheckData = $this->inboxReceiverCorrection($draftId, $receiverAsReviewData);
            $allReceiver = $inboxReceiverCorrectionsForCheckData->get()->pluck('To_Id')->toArray();
            $firstReceiver = $inboxReceiverCorrectionsForCheckData->first();
            $checkDataLatest = array_count_values($allReceiver);

            if ($checkDataLatest[$firstReceiver->To_Id] == 1) {
                $inboxReceiverCorrections->where('To_Id', '!=', $firstReceiver->To_Id);
            }
        }

        return $inboxReceiverCorrections->get()->unique('To_Id');
    }

    /**
     * inboxReceiverCorrection
     *
     * @param  string $draftId
     * @param  array $receiverAsReviewData
     * @return object
     */
    public function inboxReceiverCorrection($draftId, $receiverAsReviewData)
    {
        return InboxReceiverCorrection::where('NId', $draftId)
                                ->whereIn('ReceiverAs', $receiverAsReviewData)
                                ->orderBy('ReceiveDate', 'desc');
    }
}
