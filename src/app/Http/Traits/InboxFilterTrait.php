<?php

namespace App\Http\Traits;

use App\Enums\InboxFilterTypeEnum;
use App\Enums\InboxReceiverScopeType;
use App\Enums\ListTypeEnum;
use App\Enums\PeopleGroupTypeEnum;
use App\Enums\PeopleRoleIdTypeEnum;
use Illuminate\Support\Arr;

/**
 * Filter inbox data with parameters
 */
trait InboxFilterTrait
{
    /**
     * Filtering list by status
     *
     * @param Object $query
     * @param Array $filter
     *
     * @return Void
     */
    private function filterByStatus($query, $filter)
    {
        $statuses = $filter["statuses"] ?? null;
        if ($statuses) {
            $arrayStatuses = explode(", ", $statuses);
            $query->whereIn('StatusReceive', $arrayStatuses);
        }
    }

    /**
     * Filtering list by sources types
     *
     * @param Object $query
     * @param Array $filter
     *
     * @return Void
     */
    private function filterByResource($query, $filter)
    {
        $sources = $filter["sources"] ?? null;
        if ($sources) {
            $arraySources = explode(", ", $sources);
            $query->whereIn('NId', function ($inboxQuery) use ($arraySources) {
                $inboxQuery->select('NId')
                ->from('inbox')
                ->whereIn('Pengirim', $arraySources);
            });
        }
    }

    /**
     * Filtering list by folder types
     *
     * @param Object $query
     * @param Array $filter
     *
     * @return Void
     */
    private function filterByFolder($query, $filter)
    {
        $folder = $filter["folder"] ?? null;
        if ($folder) {
            $arrayFolders = explode(", ", $folder);
            $query->whereIn('NId', function ($inboxQuery) use ($arrayFolders) {
                $inboxQuery->select('NId')
                ->from('inbox')
                ->whereIn('NTipe', $arrayFolders);
            });
            $receiverTypes = $filter["receiverTypes"] ?? null;
            if (!$receiverTypes) {
                $query->where('ReceiverAs', 'to');
            }
        }
    }

    /**
     * Filtering list by forward status types
     *
     * @param Object $query
     * @param Array $filter
     *
     * @return Void
     */
    private function filterByForwardStatus($query, $filter)
    {
        $forwarded = $filter["forwarded"] ?? null;
        if ($forwarded || $forwarded != null) {
            $arrayForwarded = explode(", ", $forwarded);
            $query->whereIn('Status', $arrayForwarded);
        }
    }

    /**
     * Filtering list by receiver types
     *
     * @param Object $query
     * @param Array $filter
     *
     * @return Void
     */
    private function filterByReceiverTypes($query, $filter)
    {
        $receiverTypes = $filter["receiverTypes"] ?? null;
        if ($receiverTypes) {
            $arrayReceiverTypes = explode(", ", $receiverTypes);
            if (in_array('nondisposition', $arrayReceiverTypes)) {
                $this->nondispositionQuery($query, $arrayReceiverTypes);
            } else {
                $query->whereIn('ReceiverAs', $arrayReceiverTypes);
            }
        }
    }

    /**
     * Filtering nondisposition types
     * Nondisposition is letter that:
     * - not a draft
     * - not a bcc/cc1
     * - not a forwarded letter from the UK
     *
     * @param Object $query
     * @param Array $filter
     *
     * @return Void
     */
    private function nondispositionQuery($query, $arrayReceiverTypes)
    {
        $query->where(fn($query) => $query
            ->whereIn('ReceiverAs', $this->nonDispositionReceiverAsLabel())
            ->where(fn($query) => $query
                ->whereRelation('sender', 'GroupId', '!=', PeopleGroupTypeEnum::UK())
                ->orWhere('ReceiverAs', '!=', 'to_forward'))
            ->orWhereIn('ReceiverAs', $arrayReceiverTypes));
    }

    /**
     * Filtering list by scope types.
     * If the RoleId_From from the governor (uk.1)
     * or vice governor (uk.1.1.1)
     * then should be included on IINTERNAL scope
     *
     * @param Object $query
     * @param Array $filter
     *
     * @return Void
     */
    private function filterByScope($query, $filter)
    {
        $scope = $filter["scope"] ?? null;
        if ($scope) {
            switch ($scope) {
                case InboxReceiverScopeType::REGIONAL():
                    $this->queryRegionalScope($query, $filter);
                    break;

                case InboxReceiverScopeType::INTERNAL():
                    $this->queryInternalScope($query, $filter);
                    break;

                case InboxReceiverScopeType::EXTERNAL():
                    $this->queryExternalScope($query);
                    break;
            }
        }
    }

    /**
     * Filtering list by action label
     *
     * @param Object $query
     * @param Array $filter
     *
     * @return Void
     */
    private function filterByActionLabel($query, $filter)
    {
        $labels = $filter["actionLabels"] ?? null;
        if ($labels) {
            $arraylabels = explode(", ", $labels);
            $query->whereIn('action_label', $arraylabels);
        }
    }

    /**
     * Query REGIONAL scope filter
     * Letters forwarded by UK
     * Letters sent by different user role code
     *
     * @param Object $query
     * @param Array $filter
     *
     * @return Void
     */
    private function queryRegionalScope($query, $filter)
    {
        $receiverTypes = $filter['receiverTypes'] ?? null;
        $arrayReceiverTypes = explode(', ', $receiverTypes);
        if ($receiverTypes && $arrayReceiverTypes[0] == 'to_forward') {
            $query->whereRelation('sender', 'GroupId', '=', PeopleGroupTypeEnum::UK());
        } else {
            $query->whereRelation('sender.role', 'RoleCode', '!=', auth()->user()->role->RoleCode);
        }
    }

    /**
     * Query INTERNAL scope filter
     * Letters forwarded not by UK
     * Letters sent by the same user role code
     *
     * @param Object $query
     * @param Array $filter
     *
     * @return Void
     */
    private function queryInternalScope($query, $filter)
    {
        $this->queryInternalScopeProv($query);
    }

    /**
     * Query EXTERNAL scope filter
     * Letters sent from external West Java government
     *
     * @param Object $query
     *
     * @return Void
     */
    private function queryExternalScope($query)
    {
        $query->where('ReceiverAs', 'to')
            ->whereRelation('inboxDetail', 'AsalNaskah', '=', 'eksternal');
    }

    /**
     * Query INTERNAL Province scope filter
     *
     * @param Object $query
     *
     * @return Void
     */
    private function queryInternalScopeProv($query)
    {
        $query->where(
            fn($query) => $query
                ->whereNotIn('ReceiverAs', ['bcc', 'to'])
                ->where('ReceiverAs', 'not like', 'to_draft%')
                ->orWhere(
                    fn($query) => $query
                        ->where('ReceiverAs', 'to')
                        ->whereHas('inboxDetail', fn($query) => $query
                            ->whereNull('AsalNaskah')
                            ->orWhere('AsalNaskah', '!=', 'eksternal'))
                )
        );
    }

    /**
     * Filtering list by types
     *
     * @param Object $query
     * @param Array  $filter
     * @param ListTypeEnum $listType
     *
     * @return Void
     */
    private function filterByType($query, $filter, $listType)
    {
        $types = $filter["types"] ?? null;
        if ($types) {
            $arrayTypes = explode(", ", $types);
            $this->inboxCategoryFilterQuery(
                $query,
                $arrayTypes,
                $listType,
                InboxFilterTypeEnum::TYPES()
            );
        }
    }

    /**
     * Filtering by inbox urgencies
     *
     * @param Object $query
     * @param Array  $filter
     * @param ListTypeEnum $listType
     *
     * @return Void
     */
    private function filterByUrgency($query, $filter, $listType)
    {
        $urgencies = $filter["urgencies"] ?? null;
        if ($urgencies) {
            $arrayUrgencies = explode(", ", $urgencies);
            $this->inboxCategoryFilterQuery(
                $query,
                $arrayUrgencies,
                $listType,
                InboxFilterTypeEnum::URGENCIES()
            );
        }
    }

    /**
     * Filtering by inbox followed up status
     *
     * @param Object $query
     * @param Array  $filter
     *
     * @return Void
     */
    private function filterByFollowedUpStatus($query, $filter)
    {
        $followedUp = $filter["followedUp"] ?? null;
        if ($followedUp || $followedUp != null) {
            $arrayFollowedUp = explode(", ", $followedUp);
            $this->determineFollowedUpStatus($query, $arrayFollowedUp);
        }
    }

    /**
     * Filtering by the sender depts ids
     *
     * @param Object $query
     * @param Array  $filter
     *
     * @return Void
     */
    private function filterBySenderDepts($query, $filter)
    {
        $deptsIds = $filter['senderDepts'] ?? null;
        if ($deptsIds) {
            $arrayIds = explode(", ", $deptsIds);
            $query->whereIn('NId', fn ($query)
            => $query->select('NId')
                ->from('inbox')
                ->whereIn('createdBy', fn ($query)
                => $query->select('PeopleId')
                    ->from('people')
                    ->whereIn('PrimaryRoleId', fn ($query)
                    => $query->select('RoleId')
                        ->from('role')
                        ->where(fn ($query)
                        => $query->whereIn('role.roleCode', $arrayIds)
                            ->where(fn ($query)
                            => $query->whereNull('AsalNaskah')
                                ->orWhere('AsalNaskah', '<>', 'eksternal'))
                            ->whereNull('InstansiPengirimId')
                            ->orWhereIn('InstansiPengirimId', $arrayIds)))));
        }
    }

    /**
     * Followed up status filter determination
     *
     * @param Object $query
     * @param Array  $filterValue
     *
     * @return Void
     */
    private function determineFollowedUpStatus($query, $filterValue)
    {
        if (count($filterValue) == 1) {
            if (in_array('1', $filterValue)) {
                $query->whereIn('TindakLanjut', $filterValue);
            } elseif (in_array('0', $filterValue)) {
                $query->whereNull('TindakLanjut');
            }
        }
    }

    /**
     * Query for filtering based on filter category.
     *
     * @param Object                $query
     * @param Array                 $keysFilter
     * @param ListTypeEnum          $listType
     * @param InboxFilterTypeEnum   $categoryType
     *
     * @return Void
     */
    private function inboxCategoryFilterQuery($query, $keysFilter, $listType, $categoryType)
    {
        if ($categoryType === InboxFilterTypeEnum::URGENCIES()) {
            $categoryColumn = 'UrgensiId';
            $filterQuery = fn($query) => $this->urgencyQuery($query, $keysFilter);
        } else {
            $categoryColumn = 'JenisId';
            $filterQuery = fn($query) => $this->typeQuery($query, $keysFilter);
        }

        $table = $this->defineListTable($listType);
        $query->whereIn('NId', fn($query) => $query->select(Arr::get($table, 'key'))
            ->from(Arr::get($table, 'name'))
            ->whereIn($categoryColumn, $filterQuery));
    }

    /**
     * Define the chosen table.
     *
     * @param ListTypeEnum $listType
     *
     * @return Array
     */
    private function defineListTable($listType)
    {
        if ($listType === ListTypeEnum::DRAFT_LIST()) {
            return array('name' => 'konsep_naskah', 'key' => 'NId_Temp');
        }
        return array('name' => 'inbox', 'key' => 'NId');
    }

    /**
     * Query for filtering based on inbox type table.
     *
     * @param Object $query
     * @param Array $keysFilter
     *
     * @return Void
     */
    private function typeQuery($query, $keysFilter)
    {
        $query->select('JenisId')->from('master_jnaskah')->whereIn('JenisId', $keysFilter);
    }

    /**
     * Query for filtering based on urgency table.
     *
     * @param Object $query
     * @param Array $keysFilter
     *
     * @return Void
     */
    private function urgencyQuery($query, $keysFilter)
    {
        $query->select('UrgensiId')->from('master_urgensi')->whereIn('UrgensiName', $keysFilter);
    }

    /**
     * ReceiverAs labels for nondisposition letter
     * @return Array
     */
    private function nonDispositionReceiverAsLabel()
    {
        return [
            'to',
            'koreksi',
            'to_edaran',
            'to_forward',
            'to_keluar',
            'to_notadinas',
            'to_pengumuman',
            'to_rekomendasi',
            'to_sket',
            'to_sprint',
            'to_super_tugas_keluar',
            'to_surat_izin_keluar'
        ];
    }
}
