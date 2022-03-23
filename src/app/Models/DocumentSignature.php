<?php

namespace App\Models;

use App\Enums\SignatureStatusTypeEnum;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentSignature extends Model
{
    use HasFactory;

    protected $connection = 'sikdweb';

    protected $table = 'm_ttd';

    public $timestamps = false;

    public function getUrlAttribute()
    {
        $path = config('sikd.base_path_file');
        $file = $path . 'ttd/sudah_ttd/' . $this->file;
        $headers = @get_headers($file);
        if ($headers && in_array('Content-Type: application/pdf', $headers)) {
            $file = $file;
        } else {
            $file = $path . 'ttd/blm_ttd/' . $this->file;
        }
        return $file;
    }

    public function documentSignatureSents()
    {
        return $this->hasMany(DocumentSignatureSent::class, 'ttd_id', 'id');
    }

    public function inboxFile()
    {
        return $this->belongsTo(InboxFile::class, 'file', 'FileName_real');
    }

    public function documentSignatureType()
    {
        return $this->belongsTo(DocumentSignatureType::class, 'type_id', 'id');
    }

    public function getDocumentFileNameAttribute()
    {
        $title = str_replace(' ', '_', trim(preg_replace('/[^a-zA-Z0-9_ -]/s', '', substr($this->nama_file, 0, 180))));
        $pdfName = $title . '_' . parseDateTimeFormat(Carbon::now(), 'dmY')  . '_signed.pdf';

        return $pdfName;
    }
}
