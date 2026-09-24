<?php

namespace App\Actions;

use App\Models\Appointment;
use App\Models\BookingCalendar;
use App\Models\User;
use App\Support\BookingTime;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookAppointment
{
    public function handle(User $user, array $data): Appointment
    {
        $data['calendar_id'] = $data['calendar_id'] ?? null;
        $data['duration'] = (int) $data['duration'];
        $data['all_day'] = (bool) ($data['all_day'] ?? false);
        ksort($data);
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        $existing = Appointment::where('user_id', $user->id)->where('request_key', $data['request_key'])->first();
        if ($existing) {
            return $this->replay($existing, $hash);
        }

        $connection = $data['calendar_id'] ? $user->connection()->first() : null;
        if ($data['calendar_id'] && ! $connection) {
            throw ValidationException::withMessages(['calendar_id' => 'Connect Google before choosing a Google calendar.']);
        }

        try {
            return DB::transaction(function () use ($user, $data, $hash, $connection) {
                $calendar = $connection ? $connection->resolveCalendar($data['calendar_id']) : BookingCalendar::localFor($user);
                [$start, $end] = BookingTime::period($data);
                if ($connection) {
                    BookingCalendar::whereKey($calendar->id)->lockForUpdate()->firstOrFail();
                    if (Appointment::conflictingImported($calendar->id, $start, $end)->exists()) {
                        throw ValidationException::withMessages([! empty($data['all_day']) ? 'date' : 'start_time' => 'This calendar already has an event at that time. Choose another slot.']);
                    }
                }
                $appointment = Appointment::create([
                    'user_id' => $user->id,
                    'calendar_connection_id' => $connection?->id,
                    'booking_calendar_id' => $calendar->id,
                    'request_key' => $data['request_key'],
                    'request_hash' => $hash,
                    'title' => $data['title'],
                    'customer_name' => $data['customer_name'],
                    'customer_email' => $data['customer_email'],
                    'timezone' => $data['timezone'],
                    'starts_at' => $start,
                    'ends_at' => $end, 'all_day' => $data['all_day'],
                    'sync_status' => $connection ? 'pending' : 'local',
                    'next_sync_at' => $connection ? now() : null,
                ]);
                $connection?->update(['selected_calendar_id' => $calendar->id]);

                return $appointment;
            }, attempts: 3);
        } catch (QueryException $exception) {
            if (in_array($exception->getCode(), ['23505', '23P01'], true)) {
                $duplicate = Appointment::where('user_id', $user->id)->where('request_key', $data['request_key'])->first();
                if ($duplicate) {
                    return $this->replay($duplicate, $hash);
                }
            }
            if ($exception->getCode() === '23P01') {
                throw ValidationException::withMessages([! empty($data['all_day']) ? 'date' : 'start_time' => 'This calendar already has a reservation at that time. Choose another slot.']);
            }
            throw $exception;
        }
    }

    private function replay(Appointment $appointment, string $hash): Appointment
    {
        if (! hash_equals($appointment->request_hash, $hash)) {
            throw ValidationException::withMessages(['request_key' => 'This booking request was already used. Reopen the form and try again.']);
        }

        return $appointment;
    }
}
