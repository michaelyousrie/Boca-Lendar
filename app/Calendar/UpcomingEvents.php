<?php

namespace App\Calendar;

use App\Models\Appointment;
use Carbon\CarbonImmutable;

class UpcomingEvents
{
    public function forUser(int $userId, string $timezone): array
    {
        $now = CarbonImmutable::now($timezone);
        $query = Appointment::with('calendar')->where('user_id', $userId)->where('status', 'scheduled')->orderBy('starts_at');
        $timed = (clone $query)->where('all_day', false)->where('starts_at', '>', $now)->limit(3)->get();
        $allDay = (clone $query)->where('all_day', true)->whereRaw('(starts_at AT TIME ZONE appointments.timezone)::date >= ?::date', [$now->toDateString()])->reorder()->orderByRaw('(starts_at AT TIME ZONE appointments.timezone)::date')->limit(3)->get();

        return $timed->concat($allDay)->sortBy(fn ($appointment) => $appointment->displayStart($timezone)->timestamp)->take(3)->values()
            ->map(fn ($appointment) => [
                'id' => $appointment->id, 'title' => $appointment->title,
                'starts_at' => $appointment->displayStart($timezone)->toIso8601String(),
                'date' => $appointment->displayStart($timezone)->toDateString(), 'all_day' => $appointment->all_day,
                'calendar_id' => $appointment->calendar_connection_id ? $appointment->calendar->external_id : null,
            ])->all();
    }
}
