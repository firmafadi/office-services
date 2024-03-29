<?php

namespace App\Models;

use App\Enums\InboxFileTypeEnum;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

class Draft extends Model
{
    use HasFactory;

    protected $connection = 'sikdweb';

    protected $table = 'konsep_naskah';

    protected $keyType = 'string';

    protected $primaryKey = 'NId_Temp';

    public $timestamps = false;

    protected $fillable = [
        'RoleId_From',
        'Approve_People',
        'Nama_ttd_konsep'
    ];

    public function type()
    {
        return $this->belongsTo(DocumentType::class, 'JenisId', 'JenisId');
    }

    public function urgency()
    {
        return $this->belongsTo(DocumentUrgency::class, 'UrgensiId', 'UrgensiId');
    }

    public function createdBy()
    {
        return $this->belongsTo(People::class, 'CreateBy', 'PeopleId');
    }

    public function reviewer()
    {
        return $this->belongsTo(People::class, 'Approve_People', 'PeopleId');
    }

    public function approver()
    {
        return $this->belongsTo(People::class, 'Approve_People3', 'PeopleId');
    }

    public function draftType()
    {
        return $this->belongsTo(DocumentType::class, 'JenisId', 'JenisId');
    }

    public function classified()
    {
        return $this->belongsTo(MasterClassified::class, 'SifatId', 'SifatId');
    }

    public function measureUnit()
    {
        return $this->belongsTo(MasterMeasureUnit::class, 'MeasureUnitId', 'MeasureUnitId');
    }

    public function classification()
    {
        return $this->belongsTo(Classification::class, 'ClId', 'ClId');
    }

    public function inboxFile()
    {
        return $this->belongsTo(InboxFile::class, 'NId_Temp', 'NId');
    }

    public function documentDraftFile()
    {
        return $this->inboxFile()->where('Keterangan', '!=',  InboxFileTypeEnum::ATTACHMENT_DOCUMENT());
    }

    public function inboxFiles()
    {
        return $this->hasMany(InboxFile::class, 'NId', 'NId_Temp');
    }

    public function attachments()
    {
        return $this->inboxFiles()->where('Keterangan', InboxFileTypeEnum::ATTACHMENT_DOCUMENT());
    }

    public function getDraftFileAttribute()
    {
        $file = URL::to('/api/v1/draft/' . $this->NId_Temp);
        if ($this->documentDraftFile) {
            $file = $this->inboxFile->url;
        }

        return $file;
    }

    public function getDraftFileForEsignAttribute()
    {
        return URL::to('/api/v1/draft/' . $this->NId_Temp);
    }

    public function getUrlPublicAttribute()
    {
        return $this->getDraftFileAttribute();
    }

    public function getAboutAttribute()
    {
        return str_replace('&nbsp;', ' ', strip_tags($this->Hal));
    }

    public function getDocumentFileNameAttribute()
    {
        $type = match ($this->Ket) {
            'outboxnotadinas'       => 'Nota_Dinas',
            'outboxsprint'          => 'Surat_Perintah_Perangkat_Daerah',
            'outboxsprintgub'       => 'Surat_Perintah_Gubernur',
            'outboxundangan'        => 'Surat_Undangan',
            'outboxedaran'          => 'Surat_Edaran',
            'outboxinstruksigub'    => 'Surat_Instruksi_Gubernur',
            'outboxsupertugas'      => 'Surat_Pernyataan_Melaksanakan_Tugas',
            'outboxkeluar'          => 'Surat_Dinas',
            'outboxsket'            => 'Surat_Keterangan',
            'outboxpengumuman'      => 'Pengumuman',
            'outboxsuratizin'       => 'Surat_Izin',
            'outboxrekomendasi'     => 'Surat_Rekomendasi',
            default                 => 'Nadin_Lain',
        };

        /* we need to get title value with rule
         * max length 180 character, remove the unused special character and change space with underscore
         */
        $title = str_replace(' ', '_', trim(preg_replace('/[^a-zA-Z0-9_ -]/s', '', substr($this->about, 0, 180))));
        $time = parseDateTimeFormat(Carbon::now(), 'dmY') . '_' . parseDateTimeFormat(Carbon::now(), 'His');
        $pdfName = $type  . '_' . $title . '_' . $time  . '_signed.pdf';

        return $pdfName;
    }

    public function getDocumentTemplateNameAttribute()
    {
        $label = match ($this->Ket) {
            'outboxnotadinas'       => 'pdf.nota_dinas',
            'outboxsprint'          => 'pdf.surat_perintah',
            'outboxsprintgub'       => 'pdf.sprintgub',
            'outboxundangan'        => 'pdf.undangan',
            'outboxedaran'          => 'pdf.surat_edaran',
            'outboxinstruksigub'    => 'pdf.surat_instruksi',
            'outboxsupertugas'      => 'pdf.surat_supertugas',
            'outboxkeluar'          => 'pdf.surat_dinas',
            'outboxsket'            => 'pdf.surat_keterangan',
            'outboxpengumuman'      => 'pdf.pengumuman',
            'outboxsuratizin'       => 'pdf.surat_izin',
            'outboxrekomendasi'     => 'pdf.surat_rekomendasi',
            default                 => 'pdf.nadin_lain',
        };

        return $label;
    }

    public function getCategoryFooterAttribute()
    {
        switch ($this->Ket) {
            case 'outboxinstruksigub':
                return 3;
                break;

            default:
                return 1;
                break;
        }
    }
}
