<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAppointmentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'request_key' => ['required', 'uuid'],
            'calendar_id' => ['nullable', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:150'],
            'customer_name' => ['required', 'string', 'max:100'],
            'customer_email' => ['required', 'email:rfc', 'max:254'],
            'date' => ['required', 'date_format:Y-m-d'],
            'all_day' => ['sometimes', 'boolean'],
            'start_time' => ['exclude_if:all_day,true', 'required', 'date_format:H:i'],
            'timezone' => ['required', Rule::in(timezone_identifiers_list())],
            'duration' => ['required', 'integer', 'min:'.($this->boolean('all_day') ? 1 : 5), 'max:'.($this->boolean('all_day') ? 365 : 525600)],
        ];
    }
}
