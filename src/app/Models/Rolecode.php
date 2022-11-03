<?php

namespace App\Models;

use App\Enums\ListTypeEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Rolecode extends Model
{
    use HasFactory;

    protected $connection = 'sikdweb';

    public $timestamps = false;

    protected $table = 'rolecode';

    protected $keyType = 'string';

    protected $primaryKey = 'rolecode_id';

    public function filter($query, $filter)
    {
        $this->filterByListType($query, $filter);
        return $query;
    }

    /**
     * filter OPD list based on list type: Inbox (as default) or Signature.
     * The Ibox type will return all OPD
     *
     * @param  Object  $query
     * @param  String  $roleId
     *
     * @return Void
     */
    private function filterByListType($query, $filter)
    {
        $listType = $filter['listType'] ?? null;
        if ($listType == ListTypeEnum::SIGNATURE_LIST()) {
            $this->signatureListFilter($query);
        }
    }

    /**
     * OPD for signature list
     *
     * @param Object $query
     *
     * @return Void
     */
    private function signatureListFilter($query)
    {
        $userId = auth()->user()->PeopleId;
        $OPDIds = DocumentSignatureSent::select('rolecode')
            ->leftJoin('People as p', 'm_ttd_kirim.PeopleID', '=', 'p.PeopleId')
            ->leftJoin('role as r', 'p.PrimaryRoleId', '=', 'r.RoleId')
            ->whereRelation('receiver', 'PeopleId', '=', $userId)
            ->distinct()
            ->pluck('rolecode');
        $query->whereIn('rolecode_id', $OPDIds);
    }
}
