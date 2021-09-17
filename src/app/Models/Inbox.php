<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Inbox extends Model
{
    use HasFactory;

    protected $table = "inbox";

    protected $keyType = 'string';

    protected $primaryKey = 'NId';

    public function type()
    {
        return $this->belongsTo(DocumentType::class, 'JenisId', 'JenisId');
    }

    public function urgency()
    {
        return $this->belongsTo(DocumentUrgency::class, 'UrgensiId', 'UrgensiId');
    }

    public function file()
    {
        return $this->belongsTo(InboxFile::class, 'NId', 'NId');
    }

    public function getDocumentFileAttribute()
    {
        if ($this->file) {
            return config('sikd.base_path_file') . $this->NFileDir . '/' . $this->file->FileName_fake;
        }
    }

    public function owner($query)
    {
        return $query->where('CreatedBy', request()->people->PeopleId);
    }

    public function filter($query, $filter)
    {
        $sources = $filter["sources"] ?? null;
        $types = $filter["types"] ?? null;
        $urgencies = $filter["urgencies"] ?? null;

        if ($sources) {
            $query->whereIn('pengirim', $sources);
        }

        if ($types) {
            $query->whereIn('JenisId', function ($subQuery) use ($types) {
                $subQuery->select('JenisId')
                ->from('master_jnaskah')
                ->whereIn('JenisName', $types);
            });
        }

        if ($urgencies) {
            $query->whereIn('UrgensiId', function ($subQuery) use ($urgencies) {
                $subQuery->select('UrgensiId')
                ->from('master_urgensi')
                ->whereIn('UrgensiName', $urgencies);
            });
        }

        return $query;
    }
}
