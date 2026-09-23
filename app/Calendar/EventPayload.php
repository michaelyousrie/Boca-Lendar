<?php

namespace App\Calendar;

use App\Models\Appointment;

class EventPayload
{
    public static function for(Appointment $appointment): array
    {
        return [
            'id' => $appointment->eventId(),
            'summary' => $appointment->title,
            'description' => "Customer: {$appointment->customer_name}\nEmail: {$appointment->customer_email}",
            'start' => $appointment->all_day ? ['date' => $appointment->starts_at->setTimezone($appointment->timezone)->toDateString()] : ['dateTime' => $appointment->starts_at->toRfc3339String(), 'timeZone' => $appointment->timezone],
            'end' => $appointment->all_day ? ['date' => $appointment->ends_at->setTimezone($appointment->timezone)->toDateString()] : ['dateTime' => $appointment->ends_at->toRfc3339String(), 'timeZone' => $appointment->timezone],
            'extendedProperties' => ['private' => ['appointment_id' => $appointment->id, 'customer_name' => $appointment->customer_name, 'customer_email' => $appointment->customer_email]],
        ];
    }
}
