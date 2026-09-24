<?php

namespace App\Actions;

use App\Models\Appointment;
use App\Models\BookingCalendar;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SyncExistingAppointment
{
    public function handle(User $user, Appointment $appointment, string $calendarId): Appointment
    {
        $connection = $user->connection()->first();
        if (! $connection) {
            throw ValidationException::withMessages(['calendar_id' => 'Connect Google before syncing this appointment.']);
        }

        try {
            return DB::transaction(function () use ($appointment, $connection, $calendarId) {
                $calendar = $connection->resolveCalendar($calendarId);
                BookingCalendar::whereKey($calendar->id)->lockForUpdate()->firstOrFail();
                $locked = Appointment::whereKey($appointment->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === 'cancelled') {
                    throw ValidationException::withMessages(['calendar_id' => 'Cancelled appointments cannot be sent to Google.']);
                }
                if ($locked->calendar_connection_id) {
                    if ($locked->booking_calendar_id !== $calendar->id) {
                        throw ValidationException::withMessages(['calendar_id' => 'This appointment already has a Google destination.']);
                    }

                    return $locked;
                }
                if (Appointment::conflictingImported($calendar->id, $locked->starts_at, $locked->ends_at)->exists()) {
                    throw ValidationException::withMessages(['calendar_id' => 'That Google calendar already has an event at this time. Your appointment is still saved locally.']);
                }
                $locked->update([
                    'calendar_connection_id' => $connection->id, 'booking_calendar_id' => $calendar->id,
                    'sync_status' => 'pending', 'sync_attempts' => 0, 'sync_error' => null,
                    'next_sync_at' => now(), 'synced_at' => null,
                ]);
                $connection->update(['selected_calendar_id' => $calendar->id]);

                return $locked;
            }, attempts: 3);
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23P01') {
                throw ValidationException::withMessages(['calendar_id' => 'That Google calendar already has a reservation at this time. Your appointment is still saved locally.']);
            }
            throw $exception;
        }
    }
}
