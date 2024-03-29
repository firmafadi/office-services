<?php

namespace App\Models;

use App\Enums\CustomReceiverTypeEnum;
use App\Enums\ObjectiveTypeEnum;
use App\Enums\ListTypeEnum;
use App\Http\Traits\InboxFilterTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InboxReceiverCorrection extends Model
{
    use HasFactory;
    use InboxFilterTrait;

    protected $connection = 'sikdweb';

    protected $table = 'inbox_receiver_koreksi';

    public $timestamps = false;

    protected $fillable = [
        'NId',
        'NKey',
        'GIR_Id',
        'From_Id',
        'RoleId_From',
        'To_Id',
        'RoleId_To',
        'ReceiverAs',
        'Msg',
        'StatusReceive',
        'ReceiveDate',
        'To_Id_Desc',
        'RoleCode',
        'JenisId',
        'id_koreksi',
        'action_label'
    ];

    public function draftDetail()
    {
        return $this->belongsTo(Draft::class, 'NId', 'NId_Temp');
    }

    public function sender()
    {
        return $this->belongsTo(People::class, 'From_Id', 'PeopleId');
    }

    public function receiver()
    {
        return $this->belongsTo(People::class, 'To_Id', 'PeopleId');
    }

    public function correction()
    {
        return $this->belongsTo(InboxCorrection::class, 'NId', 'NId');
    }

    public function personalAccessTokens()
    {
        return $this->hasMany(PersonalAccessToken::class, 'tokenable_id', 'To_Id');
    }

    public function setReceiveDateAttribute($value)
    {
        $this->attributes['ReceiveDate'] = $value->copy()->addHours(7);
    }

    public function setGirIdAttribute($value)
    {
        // GirId = peopleId + now (date in 'dmyhis' format)
        // 19 means the datetime characters numbers
        $peopleId = substr($value, 0, -19);
        $dateString = substr($value, -19);
        $date = parseDateTimeFormat($dateString, 'dmyhis');

        $this->attributes['GIR_Id'] = $peopleId . $date;
    }

    public function search($query, $search)
    {
        if ($search) {
            $query->whereIn(
                'NId',
                fn ($query) => $query
                    ->select('NId_Temp')
                    ->from('konsep_naskah')
                    ->whereRaw(
                        'MATCH(Hal) AGAINST(? IN BOOLEAN MODE)',
                        [$search . '*']
                    )
            );
        }

        return $query;
    }

    public function grouping($query, $grouping)
    {
        if ($grouping) {
            $query->distinct('NId');
        }
        return $query;
    }

    public function objective($query, $objective)
    {
        $userId = auth()->user()->PeopleId;
        $query->whereIn('NId', function ($draftQuery) {
            $draftQuery->select('NId_Temp')
                ->from('konsep_naskah');
        });

        switch ($objective) {
            case ObjectiveTypeEnum::IN():
                $query->where('From_Id', '!=', $userId)
                    ->where('ReceiverAs', '!=', 'to_koreksi');
                break;

            case ObjectiveTypeEnum::OUT():
                $query->where(fn($query) => $query->whereNull('To_Id')
                        ->orWhere('To_Id', '!=', $userId));
                break;

            case ObjectiveTypeEnum::REVISE():
                $query->where('From_Id', $userId)
                    ->where('To_Id', $userId);
                break;
        }
        return $query;
    }

    public function filter($query, $filter)
    {
        $this->filterByStatus($query, $filter);
        $this->filterByType($query, $filter, ListTypeEnum::DRAFT_LIST());
        $this->filterByUrgency($query, $filter, ListTypeEnum::DRAFT_LIST());
        $this->filterByActionLabel($query, $filter);
        $this->filterByReceiverLabel($query, $filter);
        return $query;
    }

    /**
     * Filtering list by receiver types
     *
     * @param Object $query
     * @param Array $filter
     *
     * @return Void
     */
    private function filterByReceiverLabel($query, $filter)
    {
        $receiverTypes = $filter["receiverTypes"] ?? null;
        if ($receiverTypes) {
            $arrayReceiverTypes = explode(", ", $receiverTypes);
            $this->filterReceiverLabelReviewQuery($query, $arrayReceiverTypes);
            $this->filterReceiverLabelDistributionQuery($query, $arrayReceiverTypes);
            $this->filterReceiverLabelDefaultQuery($query, $arrayReceiverTypes);
            $query->whereNotNull('action_label');
        }
    }

    /**
     * Receiver label default filter query
     *
     * @param Object $query
     * @param Array $arrayReceiverTypes
     *
     * @return Void
     */
    private function filterReceiverLabelDefaultQuery($query, $arrayReceiverTypes)
    {
        if (
            in_array(strtolower(CustomReceiverTypeEnum::REVIEW()), $arrayReceiverTypes) == false &&
            in_array(strtolower(CustomReceiverTypeEnum::DISTRIBUTION()), $arrayReceiverTypes) == false
        ) {
            $query->whereIn('ReceiverAs', $arrayReceiverTypes);
        }
    }

    /**
     * Receiver label review filter query
     *
     * @param Object $query
     * @param Array $arrayReceiverTypes
     *
     * @return Void
     */
    private function filterReceiverLabelReviewQuery($query, $arrayReceiverTypes)
    {
        if (in_array(strtolower(CustomReceiverTypeEnum::REVIEW()), $arrayReceiverTypes)) {
            $query->where(
                fn($query) => $query
                    ->whereIn('ReceiverAs', $this->getReceiverAsReviewData())
                    ->orWhere('ReceiverAs', 'meneruskan')
                    ->whereHas('receiver', fn($query) => $query->where('GroupId', '!=', 6))
            );
        }
    }

    /**
     * Receiver label distribution filter query
     * Only return 'Surat Dinas' letters with the receiver is UK (GroupId=6)
     *
     * @param Object $query
     * @param Array $arrayReceiverTypes
     *
     * @return Void
     */
    private function filterReceiverLabelDistributionQuery($query, $arrayReceiverTypes)
    {
        if (in_array(strtolower(CustomReceiverTypeEnum::DISTRIBUTION()), $arrayReceiverTypes)) {
            $query->where('ReceiverAs', 'meneruskan')
                ->whereHas('draftDetail', fn($query) => $query->where('JenisId', 'XxJyPn38Yh.35'))
                ->whereHas('receiver', fn($query) => $query->where('GroupId', 6));
        }
    }

    public function getReceiverAsReviewData()
    {
        return [
            'to_draft_keluar',
            'to_draft_notadinas',
            'to_draft_edaran',
            'to_draft_sprint',
            'to_draft_instruksi_gub',
            'to_draft_sket',
            'to_draft_super_tugas',
            'to_draft_pengumuman',
            'to_draft_surat_izin',
            'to_draft_rekomendasi'
        ];
    }

    public function getReceiverAsLabelAttribute()
    {
        $label = match ($this->ReceiverAs) {
            'approvenaskah'         => 'TTE Naskah',
            'meneruskan'            => 'Review Naskah',
            'Meminta Nomber Surat'  => 'Penomoran Naskah',
            'koreksi'               => 'Perbaiki Naskah',
            default                 => 'Review Naskah'
        };

        return $label;
    }
}
