<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MasterAnnouncement extends Model
{
    use HasFactory;

    protected $connection = 'sikdweb';

    protected $table = 'master_notice';

    public $timestamps = false;

    protected $primaryKey = 'notice_id';
    
    public function scopePriority($query)
    {
        return $query->where('priority', '=', 0);
    }
}