<?php

namespace App\Models;

use App\Enums\PeopleGroupTypeEnum;
use App\Http\Traits\InboxFilterTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InboxReceiverTemp extends Model
{
    use HasFactory;

    protected $connection = 'sikdweb';

    protected $table = 'inbox_receiver_temp';

    public $timestamps = false;

    protected $appends = ['purpose'];

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
        'Status',
        'TindakLanjut',
        'action_label'
    ];

    public function inboxDetail()
    {
        return $this->belongsTo(Inbox::class, 'NId', 'NId');
    }

    public function sender()
    {
        return $this->belongsTo(People::class, 'From_Id', 'PeopleId');
    }

    public function receiver()
    {
        return $this->belongsTo(People::class, 'To_Id', 'PeopleId');
    }

    public function senderByRoleId()
    {
        return $this->belongsTo(People::class, 'RoleId_From', 'PrimaryRoleId');
    }

    public function receiverByRoleId()
    {
        if (auth()->user()->GroupId == PeopleGroupTypeEnum::TU()->value) {
            return $this->belongsTo(People::class, 'RoleId_To', 'PrimaryRoleId');
        }
        return $this->receiver();
    }

    public function getPurposeAttribute()
    {
        return InboxReceiver::where('NId', $this->NId)
                        ->where('GIR_Id', $this->GIR_Id)
                        ->get();
    }
}
