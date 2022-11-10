<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EsignDocumentSignatureRequest extends FormRequest
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
            'document_signature_ids' => 'array|required_if:is_signed_self,true',
            'document_signature_ids.*' => 'exists:sikdweb.m_ttd,id|required_if:is_signed_self,true',
            'document_signature_sent_ids' => 'array|required_if:is_signed_self,false',
            'document_signature_sent_ids.*' => 'exists:sikdweb.m_ttd_kirim,id|required_if:is_signed_self,false',
            'people_id' => 'exists:sikdweb.people,PeopleId',
            'passphrase' => 'required|string',
            'is_signed_self' => 'required|boolean',
            'esign_type' => 'required|in:SINGLEFILE,MULTIFILE',
            'fcm_token' => 'nullable|string',
        ];
    }
}
