<?php

namespace Tests\Feature\Jobs;

use App\Calendar\EventPayload;
use App\Calendar\GoogleCalendar;
use App\Jobs\SyncGoogleCalendar;
use App\Models\Appointment;
use App\Models\GoogleCalendarSync;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SyncGoogleCalendarTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function sync(array $attributes = []): GoogleCalendarSync
    {
        return GoogleCalendarSync::factory()->create(['request_token' => (string) Str::uuid(), 'requested_at' => now(), ...$attributes])->fresh();
    }

    private function runJob(GoogleCalendarSync $sync): void
    {
        (new SyncGoogleCalendar($sync->id, $sync->request_token))->handle(app(GoogleCalendar::class));
    }

    private function appointment(GoogleCalendarSync $sync, array $attributes = []): Appointment
    {
        return Appointment::factory()->create(['calendar_connection_id' => $sync->calendar_connection_id,
            'starts_at' => '2026-11-01 10:00:00', 'ends_at' => '2026-11-01 11:00:00',
            'sync_status' => 'synced', 'synced_at' => now()->subMinute(), 'next_sync_at' => null, ...$attributes])->fresh();
    }

    private function event(string $id = 'new'): array
    {
        return ['id' => $id, 'summary' => 'New title', 'start' => ['date' => '2026-11-01'], 'end' => ['date' => '2026-11-02']];
    }

    public function test_first_import_and_new_year_import_cover_the_full_booking_horizon(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 12, 31)->setTime(23, 30));
        $sync = $this->sync(['timezone' => 'Africa/Cairo', 'import_year' => 2026]);
        Appointment::factory()->imported()->create(['calendar_connection_id' => $sync->calendar_connection_id]);
        Http::fake(['*/events?*' => Http::response(['items' => [$this->event()], 'nextSyncToken' => 'new-token']),
            '*/events/*' => Http::response([], 404), '*/users/me/calendarList/*' => Http::response(['accessRole' => 'owner'])]);
        $this->runJob($sync);
        $this->assertSame(2029, $sync->fresh()->import_year);
        $this->assertSame('new-token', $sync->fresh()->sync_token);
        $this->assertSame(['new'], Appointment::where('status', 'scheduled')->whereNotNull('google_event_id')->pluck('google_event_id')->all());
        $this->assertNull($sync->fresh()->request_token);
        Http::assertSent(fn ($request) => ! isset($request['syncToken']) && ($request['timeMin'] ?? null) === '2026-01-01T00:00:00+02:00' && $request['timeMax'] === '2030-01-01T00:00:00+02:00');
    }

    public function test_an_existing_one_year_sync_token_is_replaced_with_an_import_covering_the_booking_horizon(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 9, 24));
        $sync = $this->sync(['import_year' => 2026]);
        $event = ['id' => 'future-event', 'summary' => 'Future appointment',
            'start' => ['date' => '2028-03-01'], 'end' => ['date' => '2028-03-02']];
        Http::fake(['*/events?*' => fn ($request) => Http::response([
            'items' => ! isset($request['syncToken']) && ($request['timeMax'] ?? null) === '2029-01-01T00:00:00+00:00' ? [$event] : [],
            'nextSyncToken' => 'expanded-token',
        ])]);

        $this->runJob($sync);

        $this->assertDatabaseHas('appointments', ['google_event_id' => 'future-event', 'booking_calendar_id' => $sync->connection->selected_calendar_id]);
        $this->assertSame(2028, $sync->fresh()->import_year);
        $this->assertSame('expanded-token', $sync->fresh()->sync_token);
    }

    public function test_incremental_sync_upserts_changes_removes_tombstones_and_keeps_unchanged_events(): void
    {
        $sync = $this->sync();
        foreach (['unchanged', 'edited', 'deleted'] as $id) {
            Appointment::factory()->imported()->create(['calendar_connection_id' => $sync->calendar_connection_id, 'google_event_id' => $id]);
        }
        Http::fake(['*/events?*' => Http::response(['items' => [$this->event('edited'), $this->event(), ['id' => 'deleted', 'status' => 'cancelled']], 'nextSyncToken' => 'next'])]);
        $this->runJob($sync);
        $this->assertSame(['edited', 'new', 'unchanged'], Appointment::where('status', 'scheduled')->orderBy('google_event_id')->pluck('google_event_id')->all());
        $this->assertDatabaseHas('appointments', ['google_event_id' => 'edited', 'title' => 'New title', 'all_day' => true]);
        $this->assertSame('next', $sync->fresh()->sync_token);
        Http::assertSent(fn ($request) => $request['syncToken'] === 'saved-token' && ! isset($request['timeMin']) && ! isset($request['timeMax']) && ! isset($request['orderBy']));
    }

    public function test_a_new_connection_imports_the_booking_horizon_and_google_restores_reactivate_bookings(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 9, 23));
        $sync = $this->sync(['sync_token' => null, 'import_year' => null]);
        $appointment = $this->appointment($sync, ['status' => 'cancelled', 'holds_slot' => false]);
        Http::fake(['*/events?*' => Http::response(['items' => [EventPayload::for($appointment)], 'nextSyncToken' => 'first'])]);
        $this->runJob($sync);
        $this->assertSame('scheduled', $appointment->fresh()->status);
        $this->assertTrue($appointment->fresh()->holds_slot);
        $this->assertSame(2028, $sync->fresh()->import_year);
        Http::assertSent(fn ($request) => ($request['timeMin'] ?? null) === '2026-01-01T00:00:00+00:00' && $request['timeMax'] === '2029-01-01T00:00:00+00:00');
    }

    public function test_empty_incremental_sync_preserves_events_and_advances_the_token(): void
    {
        $sync = $this->sync();
        $event = Appointment::factory()->imported()->create(['calendar_connection_id' => $sync->calendar_connection_id]);
        Http::fake(['*/events?*' => Http::response(['items' => [], 'nextSyncToken' => 'next'])]);
        $this->runJob($sync);
        $this->assertModelExists($event);
        $this->assertSame('next', $sync->fresh()->sync_token);
    }

    public function test_expired_token_rebuilds_only_google_data_after_a_complete_import(): void
    {
        $sync = $this->sync();
        $local = Appointment::factory()->local()->create();
        Appointment::factory()->imported()->create(['calendar_connection_id' => $sync->calendar_connection_id]);
        Http::fake(['*/events?*' => Http::sequence()->push([], 410)->push(['items' => [$this->event()], 'nextSyncToken' => 'reset']),
            '*/events/*' => Http::response([], 404), '*/users/me/calendarList/*' => Http::response(['accessRole' => 'owner'])]);
        $this->runJob($sync);
        $this->assertSame(['new'], Appointment::where('status', 'scheduled')->whereNotNull('google_event_id')->pluck('google_event_id')->all());
        $this->assertModelExists($local);
        $this->assertSame('reset', $sync->fresh()->sync_token);
        Http::assertSentCount(4);
    }

    #[DataProvider('failures')]
    public function test_failed_syncs_preserve_all_events_and_the_previous_token(int $status): void
    {
        $sync = $this->sync();
        $event = Appointment::factory()->imported()->create(['calendar_connection_id' => $sync->calendar_connection_id]);
        Http::fake(['*/events?*' => Http::sequence()->push(['items' => [$this->event()], 'nextPageToken' => 'page2'])->push([], $status)]);
        $this->runJob($sync);
        $this->assertModelExists($event);
        $this->assertDatabaseCount('appointments', 1);
        $this->assertSame('saved-token', $sync->fresh()->sync_token);
        $this->assertNotNull($sync->fresh()->sync_error);
        $this->assertNull($sync->fresh()->request_token);
    }

    public static function failures(): array
    {
        return [[401], [403], [429], [503]];
    }

    public function test_google_deletion_cancels_an_owned_booking_and_releases_its_slot_without_echoing_a_delete(): void
    {
        $sync = $this->sync();
        $appointment = $this->appointment($sync);
        Http::fake(['*/events?*' => Http::response(['items' => [['id' => $appointment->eventId(), 'status' => 'cancelled']], 'nextSyncToken' => 'next'])]);
        $this->runJob($sync);
        $this->assertSame('cancelled', $appointment->fresh()->status);
        $this->assertSame('synced', $appointment->fresh()->sync_status);
        $this->assertFalse($appointment->fresh()->holds_slot);
        $this->appointment($sync);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_missing_bookings_are_checked_individually_on_full_import_and_moved_events_are_not_cancelled(): void
    {
        $sync = $this->sync(['sync_token' => null]);
        $deleted = $this->appointment($sync);
        $moved = $this->appointment($sync, ['starts_at' => '2026-11-01 12:00:00', 'ends_at' => '2026-11-01 13:00:00']);
        Http::fake([
            '*/events?*' => Http::response(['items' => [], 'nextSyncToken' => 'next']),
            '*/events/'.$deleted->eventId() => Http::response([], 410),
            '*/events/'.$moved->eventId() => Http::response(EventPayload::for($moved) + ['status' => 'confirmed']),
            '*/users/me/calendarList/*' => Http::response(['accessRole' => 'owner']),
        ]);
        $this->runJob($sync);
        $this->assertSame('cancelled', $deleted->fresh()->status);
        $this->assertSame('scheduled', $moved->fresh()->status);
        Http::assertSentCount(4);
    }

    public function test_google_changes_update_linked_booking_details_without_overwriting_pending_local_work(): void
    {
        $sync = $this->sync();
        $appointment = $this->appointment($sync);
        $pending = $this->appointment($sync, ['sync_status' => 'pending', 'starts_at' => '2026-11-01 12:00:00', 'ends_at' => '2026-11-01 13:00:00']);
        $updated = EventPayload::for($appointment);
        $updated['summary'] = 'Changed in Google';
        $updated['start'] = ['dateTime' => '2026-12-02T10:00:00Z'];
        $updated['end'] = ['dateTime' => '2026-12-02T11:00:00Z'];
        Http::fake(['*/events?*' => Http::response(['items' => [$updated, ['id' => $pending->eventId(), 'status' => 'cancelled']], 'nextSyncToken' => 'next'])]);
        $this->runJob($sync);
        $this->assertSame('Changed in Google', $appointment->fresh()->title);
        $this->assertSame('2026-12-02', $appointment->fresh()->starts_at->toDateString());
        $this->assertSame('cancelled', $pending->fresh()->status);
        $this->assertSame('synced', $pending->fresh()->sync_status);
    }

    public function test_series_changes_replace_occurrences_and_series_deletion_removes_all_instances(): void
    {
        $sync = $this->sync();
        Appointment::factory()->imported()->create(['calendar_connection_id' => $sync->calendar_connection_id, 'google_event_id' => 'old-instance', 'recurring_event_id' => 'series']);
        Http::fake([
            '*/events?*' => Http::sequence()->push(['items' => [['id' => 'series', 'recurrence' => ['RRULE:FREQ=WEEKLY']]], 'nextSyncToken' => 'one'])
                ->push(['items' => [['id' => 'series', 'status' => 'cancelled']], 'nextSyncToken' => 'two']),
            '*/events/series/instances?*' => Http::response(['items' => [$this->event('instance')]]),
        ]);
        $this->runJob($sync);
        $this->assertSame(['instance'], Appointment::where('status', 'scheduled')->whereNotNull('google_event_id')->pluck('google_event_id')->all());
        $sync->refresh()->update(['request_token' => (string) Str::uuid()]);
        $this->runJob($sync);
        $this->assertSame(0, Appointment::where('status', 'scheduled')->count());
    }

    #[DataProvider('races')]
    public function test_stale_sync_cannot_undo_concurrent_local_changes(string $change): void
    {
        $sync = $this->sync();
        $appointment = $this->appointment($sync);
        $event = Appointment::factory()->imported()->create(['calendar_connection_id' => $sync->calendar_connection_id]);
        Http::fake(['*/events?*' => function () use ($sync, $appointment, $event, $change) {
            if ($change === 'event') {
                $event->delete();
                $sync->update(['request_token' => null]);
            } elseif ($change === 'deleted-booking') {
                $appointment->delete();
            } else {
                $appointment->update(match ($change) {
                    'cancelled' => ['status' => 'cancelled', 'sync_status' => 'pending'],
                    'pending' => ['sync_status' => 'pending'],
                    default => ['updated_at' => now()->addSecond()],
                });
                if ($change === 'updated') {
                    $sync->update(['request_token' => null]);
                }
            }

            return Http::response(['items' => [['id' => $appointment->eventId(), 'status' => 'cancelled']], 'nextSyncToken' => 'next']);
        }]);
        $this->runJob($sync);
        if ($change === 'event') {
            $this->assertModelMissing($event);
            $this->assertSame('saved-token', $sync->fresh()->sync_token);
        }
        if ($change === 'deleted-booking') {
            $this->assertModelMissing($appointment);
        } elseif (in_array($change, ['cancelled', 'pending'])) {
            $this->assertFalse($appointment->fresh()->holds_slot);
            $this->assertSame('synced', $appointment->fresh()->sync_status);
        } else {
            $this->assertTrue($appointment->fresh()->holds_slot);
        }
    }

    public static function races(): array
    {
        return [['event'], ['cancelled'], ['pending'], ['updated'], ['deleted-booking']];
    }

    public function test_local_edit_while_verifying_a_missing_event_is_not_overwritten(): void
    {
        $sync = $this->sync(['sync_token' => null]);
        $appointment = $this->appointment($sync);
        Http::fake([
            '*/events?*' => Http::response(['items' => [], 'nextSyncToken' => 'next']),
            '*/events/'.$appointment->eventId() => function () use ($appointment) {
                $event = EventPayload::for($appointment);
                $appointment->update(['title' => 'Local edit', 'sync_status' => 'pending']);

                return Http::response($event + ['status' => 'confirmed']);
            },
        ]);
        $this->runJob($sync);
        $this->assertSame('Local edit', $appointment->fresh()->title);
        $this->assertSame('pending', $appointment->fresh()->sync_status);
        $this->assertSame('next', $sync->fresh()->sync_token);
    }

    public function test_removing_an_appointment_during_remote_verification_does_not_recreate_it(): void
    {
        $sync = $this->sync(['sync_token' => null]);
        $appointment = $this->appointment($sync);
        Http::fake([
            '*/events?*' => Http::response(['items' => [], 'nextSyncToken' => 'next']),
            '*/events/'.$appointment->eventId() => function () use ($appointment) {
                $event = EventPayload::for($appointment);
                $appointment->delete();

                return Http::response($event + ['status' => 'confirmed']);
            },
        ]);
        $this->runJob($sync);
        $this->assertModelMissing($appointment);
        $this->assertSame(0, Appointment::count());
        $this->assertSame('next', $sync->fresh()->sync_token);
    }

    public function test_converting_a_recurring_series_to_a_single_appointment_cancels_old_occurrences(): void
    {
        $sync = $this->sync();
        $occurrence = Appointment::factory()->imported()->create(['calendar_connection_id' => $sync->calendar_connection_id, 'google_event_id' => 'occurrence', 'recurring_event_id' => 'series']);
        Http::fake(['*/events?*' => Http::response(['items' => [$this->event('series')], 'nextSyncToken' => 'next'])]);
        $this->runJob($sync);
        $this->assertSame('cancelled', $occurrence->fresh()->status);
        $this->assertSame(['series'], Appointment::where('status', 'scheduled')->pluck('google_event_id')->all());
    }

    public function test_missing_superseded_expired_or_removed_calendars_never_contact_google(): void
    {
        $sync = $this->sync();
        (new SyncGoogleCalendar($sync->id, (string) Str::uuid()))->handle(app(GoogleCalendar::class));
        $sync->update(['requested_at' => now()->subMinutes(3)]);
        $this->runJob($sync);
        $sync->update(['requested_at' => now()]);
        $sync->connection->update(['calendars' => []]);
        $this->runJob($sync);
        $this->assertNotNull($sync->fresh()->sync_error);
        $sync->delete();
        $this->runJob($sync);
        Http::assertNothingSent();
    }

    public function test_a_conflicting_google_edit_preserves_bookings_and_the_sync_token(): void
    {
        $sync = $this->sync();
        $first = $this->appointment($sync);
        $second = $this->appointment($sync, ['starts_at' => '2026-11-01 12:00:00', 'ends_at' => '2026-11-01 13:00:00']);
        $payload = EventPayload::for($first);
        $payload['start'] = ['dateTime' => $second->starts_at->toIso8601String()];
        $payload['end'] = ['dateTime' => $second->ends_at->toIso8601String()];
        Http::fake(['*/events?*' => Http::response(['items' => [$payload], 'nextSyncToken' => 'next'])]);
        $this->runJob($sync);
        $this->assertTrue($first->fresh()->starts_at->equalTo($first->starts_at));
        $this->assertSame('saved-token', $sync->fresh()->sync_token);
        $this->assertStringContainsString('overlaps another booking', $sync->fresh()->sync_error);
    }

    public function test_unexpected_database_failures_roll_back_and_are_reported(): void
    {
        $sync = $this->sync();
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT test_reject_event CHECK (title <> 'New title')");
        Http::fake(['*/events?*' => Http::response(['items' => [$this->event()], 'nextSyncToken' => 'next'])]);
        try {
            $this->runJob($sync);
            $this->fail('Expected database failure');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode());
            $this->assertSame('saved-token', $sync->fresh()->sync_token);
            $this->assertSame(0, Appointment::where('status', 'scheduled')->count());
        }
    }

    public function test_wrong_ownership_does_not_change_a_booking_and_deletion_wins_over_an_older_copy(): void
    {
        $sync = $this->sync();
        $appointment = $this->appointment($sync);
        $copy = EventPayload::for($appointment);
        unset($copy['extendedProperties']);
        $copy['summary'] = 'Not ours';
        Http::fake(['*/events?*' => Http::response(['items' => [$copy, $this->event('deleted'), ['id' => 'deleted', 'status' => 'cancelled']], 'nextSyncToken' => 'next'])]);
        $this->runJob($sync);
        $this->assertSame($appointment->title, $appointment->fresh()->title);
        $this->assertDatabaseMissing('appointments', ['google_event_id' => 'deleted', 'status' => 'scheduled']);
    }

    public function test_worker_failure_keeps_events_and_cannot_clear_newer_work(): void
    {
        $sync = $this->sync();
        $job = new SyncGoogleCalendar($sync->id, $sync->request_token);
        $job->failed(new RuntimeException('Worker stopped'));
        $this->assertNotNull($sync->fresh()->sync_error);
        $sync->refresh()->update(['request_token' => (string) Str::uuid(), 'sync_error' => null]);
        $job->failed(null);
        $this->assertNull($sync->fresh()->sync_error);
        $this->assertNotNull($sync->fresh()->request_token);
    }
}
