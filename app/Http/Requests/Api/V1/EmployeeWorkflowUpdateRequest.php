<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeWorkflowUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['sometimes', 'string', 'max:100'],
            'gender' => ['sometimes', Rule::in(['male', 'female'])],
            'ssn' => ['sometimes', 'string', 'max:20'],
            'employment_type' => ['sometimes', Rule::in(['W2', '1099'])],

            'status_history' => ['sometimes', 'array'],
            'status_history.*.status' => ['required', Rule::in(['hired', 'resigned', 'terminated', 'rehired', 'OJE'])],
            'status_history.*.effective_date' => ['required', 'date'],
            'status_history.*.store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'status_history.*.notes' => ['nullable', 'string'],

            'pay_history' => ['sometimes', 'array'],
            'pay_history.*.base_pay' => ['required', 'numeric', 'min:0'],
            'pay_history.*.performance_pay' => ['required', 'numeric', 'min:0'],
            'pay_history.*.effective_date' => ['required', 'date'],

            'contacts' => ['sometimes', 'array'],
            'contacts.*.contact_name' => ['required', 'string', 'max:100'],
            'contacts.*.contact_type' => ['required', Rule::in(['email', 'phone', 'emergency_contact'])],
            'contacts.*.contact_value' => ['required', 'string', 'max:255'],
            'contacts.*.is_primary' => ['sometimes', 'boolean'],

            'addresses' => ['sometimes', 'array'],
            'addresses.*.address_name' => ['required', 'string', 'max:100'],
            'addresses.*.address_1' => ['required', 'string', 'max:255'],
            'addresses.*.address_2' => ['nullable', 'string', 'max:255'],
            'addresses.*.city' => ['required', 'string', 'max:100'],
            'addresses.*.state' => ['required', 'string', 'max:100'],
            'addresses.*.zip_code' => ['required', 'string', 'max:20'],
            'addresses.*.country' => ['sometimes', 'string', 'max:100'],
            'addresses.*.is_primary' => ['sometimes', 'boolean'],

            'availability' => ['sometimes', 'array'],
            'availability.*.day_of_week' => ['required', Rule::in(['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'])],
            'availability.*.shift_type' => ['required', Rule::in(['PM', 'AM', 'OP'])],
            'availability.*.times' => ['sometimes', 'array', 'min:1'],
            'availability.*.times.*.available_from' => ['required', 'date_format:H:i'],
            'availability.*.times.*.available_to' => ['required', 'date_format:H:i', 'after:availability.*.times.*.available_from'],

            'financial_info' => ['sometimes', 'array'],
            'financial_info.*.account_number' => ['required', 'string', 'max:255'],
            'financial_info.*.routing_number' => ['required', 'string', 'max:255'],
            'financial_info.*.account_type' => ['required', Rule::in(['checking', 'savings'])],
            'financial_info.*.effective_date' => ['required', 'date'],

            'employee_ids' => ['sometimes', 'array'],
            'employee_ids.*.id_type_id' => ['required', 'integer', 'exists:id_types,id'],
            'employee_ids.*.id_value' => ['required', 'string', 'max:255'],

            'obsession' => ['sometimes', 'nullable', 'array'],
            'obsession.t_shirt' => ['nullable', Rule::in(['L', 'M', 'S', 'XL', 'XS', '2XL', '3XL', '4XL', '5XL', '6XL'])],
            'obsession.birth_date' => ['required_with:obsession', 'date'],
            'obsession.image' => ['nullable', 'file', 'image', 'max:10240'],
            'obsession.image_path' => ['prohibited'],
            'obsession.religion' => ['nullable', Rule::in(['Christianity', 'Islam', 'Judaism', 'Buddhism', 'Hinduism', 'Other'])],
            'obsession.race' => ['nullable', Rule::in(['Caucasian', 'African American', 'Hispanic', 'Asian', 'Native American', 'Other'])],
            'obsession.notes' => ['nullable', 'string'],

            'positions' => ['sometimes', 'array'],
            'positions.*.position_id' => ['required', 'integer', 'exists:positions,id'],
            'positions.*.effective_date' => ['required', 'date'],

            'marital_history' => ['sometimes', 'array'],
            'marital_history.*.marital_id' => ['required', 'integer', 'exists:marital_statuses,id'],
            'marital_history.*.effective_date' => ['required', 'date'],

            'attachments' => ['sometimes', 'array'],
            'attachments.*.type_id' => ['required', 'integer', 'exists:attachment_types,id'],
            'attachments.*.file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:20480'],

            'store_assignments' => ['sometimes', 'array'],
            'store_assignments.*.store_id' => ['required', 'integer', 'exists:stores,id'],
            'store_assignments.*.effective_date' => ['required', 'date'],
        ];
    }
}
