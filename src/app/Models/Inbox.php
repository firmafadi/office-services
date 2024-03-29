<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Inbox extends Model
{
    use HasFactory;

    protected $connection = 'sikdweb';

    protected $table = 'inbox';

    protected $keyType = 'string';

    protected $primaryKey = 'NId';

    public $timestamps = false;

    public function type()
    {
        return $this->belongsTo(DocumentType::class, 'JenisId', 'JenisId');
    }

    public function urgency()
    {
        return $this->belongsTo(DocumentUrgency::class, 'UrgensiId', 'UrgensiId');
    }

    public function classified()
    {
        return $this->belongsTo(MasterClassified::class, 'SifatId', 'SifatId');
    }

    public function documentFile()
    {
        return $this->belongsTo(InboxFile::class, 'NId', 'NId');
    }

    public function draft()
    {
        return $this->belongsTo(Draft::class, 'NId', 'NId_Temp');
    }

    public function getMadeFromDraftAttribute()
    {
        return $this->draft()->exists();
    }

    public function checkFile($file)
    {
        $headers = @get_headers($file);
        if ($headers && in_array('Content-Type: application/pdf', $headers)) {
            return $file;
        }
        return false;
    }

    public function getDocumentBaseUrlAttribute()
    {
        if ($this->documentFile?->Keterangan == 'inboxsuara') {
            $checkFile = $this->checkFile(config('sikd.base_path_file') . $this->NFileDir . '/' . $this->documentFile->FileName_fake);
            if ($checkFile == false) {
                return config('sikd.url_suara');
            }
        }

        return config('sikd.base_path_file');
    }

    public function getUrlPublicAttribute()
    {
        if ($this->documentFile?->FileName_fake) {
            return $this->document_base_url . $this->NFileDir . '/' . $this->documentFile->FileName_fake;
        }

        return null;
    }

    public function createdBy()
    {
        return $this->belongsTo(People::class, 'CreatedBy', 'PeopleId');
    }

    public function setNTglRegAttribute($value)
    {
        $this->attributes['NTglReg'] = $value->copy()->addHours(7);
    }

    public function getAttachmentAttribute()
    {
        if ($this->Lampiran) {
            return $this->getDocumentBaseUrlAttribute() . 'naskah/lampiran/' . $this->Lampiran;
        }
    }
}
