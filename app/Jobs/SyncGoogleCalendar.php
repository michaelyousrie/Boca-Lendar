<?php

namespace App\Jobs;

use App\Calendar\CalendarException;
use App\Calendar\CalendarProvider;
use App\Models\Appointment;
use App\Models\BookingCalendar;
use App\Models\GoogleCalendarSync;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class SyncGoogleCalendar implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public bool $failOnTimeout = true;

    public function __construct(public int $syncId, public string $requestToken) {}

    public function handle(CalendarProvider $provider): void
    {
        $sync = GoogleCalendarSync::with('connection')->find($this->syncId);
        if (! $sync || $sync->request_token !== $this->requestToken || ! $sync->syncing()) {
            return;
        }
        $connection = $sync->connection;
        $metadata = collect($connection->calendars)->firstWhere('id', $sync->calendar_id);
        if (! $metadata) {
            $this->finish('This calendar is no longer available.');

            return;
        }
        $calendar = BookingCalendar::firstOrCreate(['provider' => 'google', 'external_id' => $sync->calendar_id], [
            'name' => $metadata['name'], 'timezone' => $metadata['timezone'],
        ]);
        $owned = Appointment::where('calendar_connection_id', $connection->id)->where('user_id', $connection->user_id)->where('booking_calendar_id', $calendar->id);
        $year = CarbonImmutable::now($sync->timezone)->year;
        $importThroughYear = $year + 2;
        $start = CarbonImmutable::create($year, 1, 1, 0, 0, 0, $sync->timezone);
        $end = $start->addYears(3);
        $range = (clone $owned)->selectRaw('min(starts_at) as first_start, max(ends_at) as last_end')->first();
        if ($range->first_start) {
            $start = $start->min(CarbonImmutable::parse($range->first_start)->setTimezone($sync->timezone)->startOfYear());
            $end = $end->max(CarbonImmutable::parse($range->last_end)->subMicrosecond()->setTimezone($sync->timezone)->startOfYear()->addYear());
        }
        try {
            $changes = $provider->changes($connection, $sync->calendar_id, $sync->import_year === $importThroughYear ? $sync->sync_token : null, $start, $end);
            $ids = [...array_column($changes['events'], 'id'), ...$changes['deleted']];
            $series = [...$changes['series'], ...$changes['deleted'], ...array_column($changes['events'], 'id')];
            $appointments = (clone $owned)->with(['calendar', 'connection'])
                ->when($changes['full'], fn ($query) => $query->overlapping($start, $end),
                    fn ($query) => $query->where(fn ($query) => $query->whereIn('google_event_id', $ids)
                        ->orWhereIn(DB::raw("replace(id::text, '-', '')"), $ids)->orWhereIn('recurring_event_id', $series)))->get();
            $events = collect($changes['events'])->keyBy('id');
            if ($changes['full']) {
                foreach ($appointments as $appointment) {
                    if ($appointment->sync_status === 'synced' && $appointment->status === 'scheduled'
                        && ! $events->has($appointment->eventId()) && ! in_array($appointment->eventId(), $changes['deleted'], true)) {
                        $event = $provider->event($appointment);
                        if ($event === null) {
                            $changes['deleted'][] = $appointment->eventId();
                        } else {
                            $events->put($event['id'], $event);
                        }
                    }
                }
            }
            DB::transaction(function () use ($sync, $connection, $calendar, $changes, $events, $appointments, $importThroughYear) {
                BookingCalendar::whereKey($calendar->id)->lockForUpdate()->firstOrFail();
                $locked = Appointment::whereIn('id', $appointments->pluck('id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $current = GoogleCalendarSync::whereKey($sync->id)->where('request_token', $this->requestToken)->lockForUpdate()->first();
                if (! $current) {
                    return;
                }
                $known = [];
                foreach ($appointments as $original) {
                    $id = $original->eventId();
                    $known[$id] = true;
                    $appointment = $locked->get($original->id);
                    if (! $appointment) {
                        continue;
                    }
                    $event = $events->get($id);
                    $deleted = in_array($id, $changes['deleted'], true)
                        || in_array($appointment->recurring_event_id, $changes['deleted'], true)
                        || (! $event && in_array($appointment->recurring_event_id, [...$changes['series'], ...$events->keys()->all()], true));
                    if ($deleted) {
                        $appointment->update(['status' => 'cancelled', 'sync_status' => 'synced', 'holds_slot' => false, 'synced_at' => now(), 'next_sync_at' => null, 'sync_error' => null, 'sync_attempts' => 0]);
                    } elseif ($original->sync_status === 'synced' && $appointment->sync_status === 'synced'
                        && $appointment->updated_at->equalTo($original->updated_at)
                        && $event && ($appointment->google_event_id || $event['appointment_id'] === $appointment->id)) {
                        $appointment->update($this->attributes($event, $appointment->timezone) + [
                            'holds_slot' => $appointment->conflict_checked,
                            'customer_name' => $event['customer_name'] ?? $appointment->customer_name,
                            'customer_email' => $event['customer_email'] ?? $appointment->customer_email,
                        ]);
                    }
                }
                $new = [];
                foreach ($events as $event) {
                    if (! isset($known[$event['id']]) && ! in_array($event['id'], $changes['deleted'], true)
                        && ! in_array($event['recurring_event_id'], $changes['deleted'], true)) {
                        $new[] = $this->attributes($event, $sync->timezone) + [
                            'id' => (string) Str::uuid(), 'user_id' => $connection->user_id,
                            'calendar_connection_id' => $connection->id, 'booking_calendar_id' => $calendar->id,
                            'customer_name' => $event['customer_name'], 'customer_email' => $event['customer_email'],
                            'holds_slot' => false, 'conflict_checked' => false, 'created_at' => now(), 'updated_at' => now(),
                        ];
                    }
                }
                foreach (array_chunk($new, 500) as $batch) {
                    Appointment::insert($batch);
                }
                $current->update(['sync_token' => $changes['sync_token'], 'import_year' => $importThroughYear, 'request_token' => null, 'synced_at' => now(), 'sync_error' => null]);
            });
        } catch (CalendarException $exception) {
            $this->finish($exception->getMessage());
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23P01') {
                throw $exception;
            }
            $this->finish('A Google change overlaps another booking. Move one appointment, then refresh appointments.');
        }
    }

    private function attributes(array $event, string $timezone): array
    {
        $timezone = $event['timezone'] ?? $timezone;

        return [
            'google_event_id' => $event['id'], 'recurring_event_id' => $event['recurring_event_id'],
            'title' => $event['title'], 'all_day' => $event['all_day'], 'timezone' => $timezone,
            'starts_at' => CarbonImmutable::parse($event['starts_at'], $timezone)->utc(),
            'ends_at' => CarbonImmutable::parse($event['ends_at'], $timezone)->utc(), 'url' => $event['url'],
            'status' => 'scheduled', 'sync_status' => 'synced', 'synced_at' => now(), 'next_sync_at' => null,
            'sync_error' => null, 'sync_attempts' => 0,
        ];
    }

    public function failed(?Throwable $exception): void
    {
        $this->finish('Google sync could not finish. Try again.');
    }

    private function finish(string $error): void
    {
        GoogleCalendarSync::whereKey($this->syncId)->where('request_token', $this->requestToken)->update(['request_token' => null, 'sync_error' => $error]);
    }
}
