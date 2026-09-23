<?php

namespace App\Actions;

use App\Models\Appointment;
use App\Support\BookingTime;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateAppointment
{
    public function handle(Appointment $appointment, array $data): Appointment
    {
        try {
            return DB::transaction(function () use ($appointment, $data) {
                $locked = Appointment::whereKey($appointment->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === 'cancelled' || ! hash_equals($locked->revision(), $data['revision'])) {
                    throw ValidationException::withMessages(['revision' => 'This appointment changed. Close the form and reopen it before editing.']);
                }
                [$start, $end] = BookingTime::period($data);
                $locked->update([
                    'title' => $data['title'], 'customer_name' => $data['customer_name'], 'customer_email' => $data['customer_email'],
                    'starts_at' => $start, 'ends_at' => $end, 'all_day' => (bool) ($data['all_day'] ?? false), 'holds_slot' => true, 'conflict_checked' => true, 'timezone' => $data['timezone'],
                    'sync_status' => $locked->calendar_connection_id ? 'pending' : 'local',
                    'sync_attempts' => 0, 'sync_error' => null,
                    'next_sync_at' => $locked->calendar_connection_id ? now() : null,
                ]);

                return $locked;
            }, attempts: 3);
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23P01') {
                throw ValidationException::withMessages([! empty($data['all_day']) ? 'date' : 'start_time' => 'This calendar already has a reservation at that time. Choose another slot.']);
            }
            throw $exception;
        }
    }
}
