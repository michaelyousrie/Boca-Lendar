<?php

namespace App\Jobs;

use App\Calendar\CalendarException;
use App\Calendar\CalendarProvider;
use App\Models\Appointment;
use App\Models\GoogleCalendarSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class SyncAppointment implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public int $uniqueFor = 120;

    public function __construct(public string $appointmentId) {}

    public function uniqueId(): string
    {
        return $this->appointmentId;
    }

    public function handle(CalendarProvider $provider): void
    {
        DB::transaction(function () use ($provider) {
            $appointment = Appointment::whereKey($this->appointmentId)->lockForUpdate()->first();
            if (! $appointment || $appointment->sync_status !== 'pending' || $appointment->next_sync_at?->isFuture()) {
                return;
            }

            try {
                // The bounded provider call shares this lock with cancellation and duplicate jobs.
                $appointment->status === 'cancelled' ? $provider->delete($appointment) : $provider->save($appointment);
                GoogleCalendarSync::where('calendar_connection_id', $appointment->calendar_connection_id)
                    ->where('calendar_id', $appointment->calendar->external_id)->update(['request_token' => null]);
                $appointment->update([
                    'sync_status' => 'synced',
                    'google_event_id' => $appointment->eventId(),
                    'synced_at' => now(),
                    'sync_error' => null,
                    'holds_slot' => $appointment->status !== 'cancelled' && $appointment->conflict_checked,
                    'next_sync_at' => null,
                ]);
            } catch (CalendarException $exception) {
                $attempts = $appointment->sync_attempts + 1;
                $retry = $exception->retryable && $attempts < config('calendar.max_attempts');
                $appointment->update([
                    'sync_status' => $retry ? 'pending' : 'failed',
                    'sync_attempts' => $attempts,
                    'sync_error' => $exception->getMessage(),
                    'next_sync_at' => $retry ? now()->addSeconds(30 * (2 ** ($attempts - 1))) : null,
                ]);
            }
        });
    }
}
