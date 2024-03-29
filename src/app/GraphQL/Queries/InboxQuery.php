<?php

namespace App\GraphQL\Queries;

use App\Enums\InboxReceiverScopeType;
use App\Enums\KafkaStatusTypeEnum;
use App\Enums\PeopleGroupTypeEnum;
use App\Enums\SignatureStatusTypeEnum;
use App\Exceptions\CustomException;
use App\Http\Traits\KafkaTrait;
use App\Models\DocumentSignatureSent;
use App\Models\InboxReceiver;
use App\Models\InboxReceiverCorrection;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class InboxQuery
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
        $inboxReceiver = InboxReceiver::find($args['id']);

        if (!$inboxReceiver) {
            throw new CustomException(
                'Inbox not found',
                'Inbox with this NId not found'
            );
        }

        $inboxReceiver->StatusReceive = 'read';
        $inboxReceiver->save();

        $this->kafkaPublish('analytic_event', [
            'event' => 'read_letter',
            'status' => KafkaStatusTypeEnum::SUCCESS(),
            'letter' => [
                'inbox_id' => $inboxReceiver->NId
            ]
        ]);

        return $inboxReceiver;
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
    public function unreadCount($rootValue, array $args, GraphQLContext $context)
    {
        $userPosition = $context->user->PeoplePosition;
        $positionGroups = call_user_func_array('array_merge', config('constants.peoplePositionGroups'));

        $found = $this->isFoundUserPosition($userPosition, $positionGroups);
        if ($found) {
            $forwardCount = $this->unreadCountDeptQuery($context);
        } else {
            $forwardCount = $this->unreadCountQuery(InboxReceiverScopeType::REGIONAL(), $context);
        }

        $internalCount = $this->unreadCountQuery(InboxReceiverScopeType::INTERNAL(), $context);
        $internalDispositionCount = $this->unreadCountQuery(InboxReceiverScopeType::INTERNAL_DISPOSITION(), $context);
        $regionalDispositionCount = $this->unreadCountQuery(InboxReceiverScopeType::REGIONAL_DISPOSITION(), $context);
        $regionalCount = (int) $forwardCount + (int) $regionalDispositionCount;
        $signatureCount = $this->unreadCountSignatureQuery($context);
        $draftCount = $this->draftUnreadCountQuery($context);
        $registrationCount = $internalDispositionCount;
        $carboncopyCount = $this->carbonCopyUnreadCountQuery($context);

        $count = [
            'forward'       => $forwardCount,
            'disposition'   => $internalDispositionCount,
            'regional'      => $regionalCount,
            'internal'      => $internalCount,
            'signature'     => $signatureCount,
            'draft'         => $draftCount,
            'registration'  => $registrationCount,
            'carboncopy'    => $carboncopyCount
        ];

        return $count;
    }

    /**
     * @param String scope
     * @param \Nuwave\Lighthouse\Support\Contracts\GraphQLContext|null $context
     *
     * @return Integer
     */
    private function unreadCountQuery($scope, GraphQLContext $context)
    {
        $user = $context->user;
        $deptCode = $user->role->RoleCode;

        $operator = $this->getRoleOperator($scope);

        $query = InboxReceiver::where('RoleId_To', $user->PrimaryRoleId)
            ->where('StatusReceive', 'unread')
            ->whereHas('sender', function ($senderQuery) use ($deptCode, $operator) {
                $senderQuery->whereHas('role', function ($roleQuery) use ($deptCode, $operator) {
                    $roleQuery->where('RoleCode', $operator, $deptCode);
                });
            });

        if ((string) $user->GroupId != PeopleGroupTypeEnum::TU()) {
            $query->where('To_Id', $user->PeopleId);
        }

        if (
            $scope == InboxReceiverScopeType::INTERNAL_DISPOSITION() ||
            $scope == InboxReceiverScopeType::REGIONAL_DISPOSITION()
        ) {
            $query->whereIn('ReceiverAs', $this->getReceiverAsRegistrationData());
        } elseif ($scope == InboxReceiverScopeType::REGIONAL()) {
            $query->whereHas('inboxDetail', fn($query) => $query->where('Pengirim', 'eksternal'));
        }

        return $query->count();
    }

     /**
     * @param \Nuwave\Lighthouse\Support\Contracts\GraphQLContext|null $context
     *
     * @return Integer
     */
    private function unreadCountDeptQuery($context)
    {
        $user = $context->user;
        $query = InboxReceiver::where('RoleId_To', $user->PrimaryRoleId)
            ->where('StatusReceive', 'unread')
            ->whereIn('ReceiverAs', ['to_forward', 'bcc'])
            ->whereHas('inboxDetail', function ($detailQuery) {
                $detailQuery->where('Pengirim', '=', 'eksternal');
            });

        if ((string) $user->GroupId != PeopleGroupTypeEnum::TU()) {
            $query->where('To_Id', $user->PeopleId);
        }

        return $query->count();
    }

     /**
     * @param \Nuwave\Lighthouse\Support\Contracts\GraphQLContext|null $context
     *
     * @return Integer
     */
    private function unreadCountSignatureQuery($context)
    {
        $user = $context->user;
        $query = DocumentSignatureSent::where(fn($query) => $query
            ->where('is_receiver_read', false)
            ->where('PeopleIDTujuan', $user->PeopleId)
            ->orWhere('PeopleID', $user->PeopleId)
                ->where('status', '!=', SignatureStatusTypeEnum::WAITING()->value)
                ->where('is_sender_read', false));

        return $query->count();
    }

    /**
     * @param String scope
     * @param \Nuwave\Lighthouse\Support\Contracts\GraphQLContext|null $context
     *
     * @return Integer
     */
    private function draftUnreadCountQuery(GraphQLContext $context)
    {
        $userId = $context->user->PeopleId;
        $query = InboxReceiverCorrection::where('To_Id', $userId)
            ->where('From_Id', '!=', $userId)
            ->where('StatusReceive', 'unread')
            ->whereIn('NId', function ($draftQuery) {
                $draftQuery->select('NId_Temp')
                    ->from('konsep_naskah');
            });

        return $query->count();
    }

    /**
     * @param \Nuwave\Lighthouse\Support\Contracts\GraphQLContext|null $context
     *
     * @return Integer
     */
    private function carbonCopyUnreadCountQuery(GraphQLContext $context)
    {
        $userId = $context->user->PeopleId;
        $query = InboxReceiver::where('To_Id', $userId)
            ->where('ReceiverAs', '=', 'bcc')
            ->where('StatusReceive', 'unread');

        return $query->count();
    }

     /**
     * @param Array $positionList
     * @param String $position
     *
     * @return Boolean
     */
    private function isFoundUserPosition($userPosition, $positionList)
    {
        foreach ($positionList as $position) {
            if (strpos($userPosition, $position) !== false) {
                return true;
            }
        }

        return false;
    }

     /**
     * @param String $scope
     *
     * @return Strin
     */
    private function getRoleOperator($scope)
    {
        switch ($scope) {
            case InboxReceiverScopeType::REGIONAL():
            case InboxReceiverScopeType::REGIONAL_DISPOSITION():
                return '!=';

            case InboxReceiverScopeType::INTERNAL():
            case InboxReceiverScopeType::INTERNAL_DISPOSITION():
                return '=';
        }
    }

     /**
     * Letter receiver type for registration list
     *
     * @return Array
     */
    private function getReceiverAsRegistrationData()
    {
        return array(
            'cc1',
            'to',
            'to_undangan',
            'to_sprint',
            'to_notadinas',
            'to_reply',
            'to_usul',
            'to_forward',
            'to_keluar',
            'to_nadin',
            'to_konsep',
            'to_memo',
            'to_edaran',
            'to_pengumuman',
            'to_rekomendasi',
            'to_super_tugas_keluar',
            'to_surat_izin_keluar',
        );
    }
}
