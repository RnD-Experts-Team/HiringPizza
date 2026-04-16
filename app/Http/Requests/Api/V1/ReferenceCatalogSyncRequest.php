<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ReferenceCatalogSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'positions' => ['sometimes', 'array'],
            'positions.*.id' => ['sometimes', 'integer', 'exists:positions,id'],
            'positions.*.lebel' => ['required_without:positions.*.id', 'string', 'max:255'],
            'positions.*.description' => ['nullable', 'string'],
            'position_delete_ids' => ['sometimes', 'array'],
            'position_delete_ids.*' => ['required', 'integer', 'exists:positions,id'],

            'marital_statuses' => ['sometimes', 'array'],
            'marital_statuses.*.id' => ['sometimes', 'integer', 'exists:marital_status,id'],
            'marital_statuses.*.label' => ['required_without:marital_statuses.*.id', 'string', 'max:255'],
            'marital_statuses.*.description' => ['nullable', 'string'],
            'marital_status_delete_ids' => ['sometimes', 'array'],
            'marital_status_delete_ids.*' => ['required', 'integer', 'exists:marital_status,id'],

            'id_types' => ['sometimes', 'array'],
            'id_types.*.id' => ['sometimes', 'integer', 'exists:id_types,id'],
            'id_types.*.label' => ['required_without:id_types.*.id', 'string', 'max:255'],
            'id_types.*.description' => ['nullable', 'string'],
            'id_type_delete_ids' => ['sometimes', 'array'],
            'id_type_delete_ids.*' => ['required', 'integer', 'exists:id_types,id'],

            'attachment_types' => ['sometimes', 'array'],
            'attachment_types.*.id' => ['sometimes', 'integer', 'exists:attachements_types,id'],
            'attachment_types.*.label' => ['required_without:attachment_types.*.id', 'string', 'max:255'],
            'attachment_types.*.description' => ['nullable', 'string'],
            'attachment_type_delete_ids' => ['sometimes', 'array'],
            'attachment_type_delete_ids.*' => ['required', 'integer', 'exists:attachements_types,id'],

            'tags' => ['sometimes', 'array'],
            'tags.*.id' => ['sometimes', 'integer', 'exists:tags,id'],
            'tags.*.label' => ['required_without:tags.*.id', 'string', 'max:255'],
            'tag_delete_ids' => ['sometimes', 'array'],
            'tag_delete_ids.*' => ['required', 'integer', 'exists:tags,id'],
        ];
    }
}
