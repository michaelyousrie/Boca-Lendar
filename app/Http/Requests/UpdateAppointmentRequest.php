<?php

namespace App\Http\Requests;

class UpdateAppointmentRequest extends StoreAppointmentRequest
{
    public function rules(): array
    {
        return array_diff_key(parent::rules(), array_flip(['request_key', 'calendar_id'])) + [
            'revision' => ['required', 'string', 'size:64'],
        ];
    }
}
