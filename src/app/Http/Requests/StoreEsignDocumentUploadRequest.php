<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEsignDocumentUploadRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'document_esign_type_id' => 'exists:sikdweb.m_ttd_types,id',
            'file' => 'required|mimes:pdf|max:15000',
            'attachment' => 'mimes:pdf|max:15000',
            'note' => 'nullable',
            'nip' => 'required|array',
            'nip.*' => 'exists:sikdweb.people,NIP'
        ];
    }
}
