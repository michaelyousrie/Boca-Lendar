<?php

namespace App\Actions;

use App\Models\Appointment;
use Illuminate\Support\Facades\DB;

class CancelAppointment
{
    public function handle(Appointment $appointment): Appointment
    {
        return DB::transaction(function () use ($appointment) {
            $locked = Appointment::whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'cancelled') {
                return $locked;
            }
            $locked->update([
                'status' => 'cancelled',
                'sync_status' => $locked->calendar_connection_id ? 'pending' : 'local',
                'holds_slot' => $locked->holds_slot && (bool) $locked->calendar_connection_id,
                'sync_attempts' => 0,
                'sync_error' => null,
                'next_sync_at' => $locked->calendar_connection_id ? now() : null,
            ]);

            return $locked;
        });
    }
}
