<?php

namespace App\Models;

use App\Enums\PeopleProposedTypeEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class People extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = "people";

    protected $primaryKey = 'PeopleId';

    public function role()
    {
        return $this->belongsTo(Role::class, 'PrimaryRoleId', 'RoleId');
    }

    public function siapPeople()
    {
        return $this->belongsTo(SiapPeople::class, 'PeopleUsername', 'peg_nip');
    }

    public function getAvatarAttribute()
    {
        return optional($this->siapPeople)->peg_foto_url;
    }

    public function filter($query, $filter)
    {
        $proposedTo = $filter["proposedTo"] ?? null;

        if ($proposedTo == PeopleProposedTypeEnum::FORWARD()) {
            $query->where('PrimaryRoleId', request()->people->PrimaryRoleId . '.1')     // head of department primary role id
                ->orWhere('PrimaryRoleId', request()->people->PrimaryRoleId . '.1.1');  // sekretary primary role id
        }

        return $query;
    }
}
