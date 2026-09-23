<?php

namespace Tests\Feature\Http;

use App\Calendar\EventPayload;
use App\Calendar\GoogleCalendar;
use App\Jobs\SyncAppointment;
use App\Jobs\SyncGoogleCalendar;
use App\Models\Appointment;
use App\Models\CalendarConnection;
use App\Models\GoogleCalendarSync;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UnifiedAppointmentsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function data(Appointment $appointment, array $overrides = []): array
    {
        return ['revision' => $appointment->revision(), 'title' => 'Updated appointment', 'customer_name' => 'Sam', 'customer_email' => 'sam@example.com',
            'date' => '2026-11-02', 'start_time' => '10:00', 'duration' => 45, 'timezone' => 'Africa/Cairo', 'all_day' => false, ...$overrides];
    }

    public function test_an_import_is_an_appointment_and_edits_save_locally_before_google_is_contacted(): void
    {
        Queue::fake([SyncAppointment::class]);
        $appointment = Appointment::factory()->imported()->create()->fresh();
        $this->actingAs(User::findOrFail($appointment->user_id))->get('/appointments?date=2026-11-01&timezone=UTC')
            ->assertInertia(fn (Assert $page) => $page->has('appointments', 1)->missing('googleEvents')
                ->where('appointments.0.id', $appointment->id)->where('appointments.0.customer_name', null)->where('appointments.0.writable', true));
        $this->post('/appointments/'.$appointment->id.'/update', $this->data($appointment))
            ->assertRedirect(route('dashboard', ['date' => '2026-11-02', 'timezone' => 'Africa/Cairo']))->assertSessionHasNoErrors();
        $updated = $appointment->fresh();
        $this->assertSame('Updated appointment', $updated->title);
        $this->assertSame('Sam', $updated->customer_name);
        $this->assertSame('2026-11-02 08:00:00', $updated->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame($appointment->google_event_id, $updated->google_event_id);
        $this->assertSame('pending', $updated->sync_status);
        Queue::assertPushed(SyncAppointment::class, fn ($job) => $job->appointmentId === $appointment->id);
        Http::assertNothingSent();
        Http::fake(['*/events/*' => Http::sequence()->push(EventPayload::for($appointment) + ['etag' => '"v1"'])->push(['id' => $appointment->eventId()])]);
        (new SyncAppointment($appointment->id))->handle(app(GoogleCalendar::class));
        $this->assertSame('synced', $appointment->fresh()->sync_status);
        Http::assertSent(fn ($request) => $request->method() === 'PATCH' && $request->hasHeader('If-Match', '"v1"')
            && $request['extendedProperties']['private']['customer_email'] === 'sam@example.com' && ! isset($request['attendees']) && ! isset($request['description']));
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_imported_appointments_require_the_same_customer_fields_and_cannot_be_edited_by_other_users(): void
    {
        Queue::fake([SyncAppointment::class]);
        $appointment = Appointment::factory()->imported()->create()->fresh();
        $url = '/appointments/'.$appointment->id.'/update';
        $this->post($url, $this->data($appointment))->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->post($url, $this->data($appointment))->assertNotFound();
        $this->actingAs(User::findOrFail($appointment->user_id))->post($url, $this->data($appointment, ['customer_name' => '', 'customer_email' => '']))->assertSessionHasErrors(['customer_name', 'customer_email']);
        $this->assertSame('synced', $appointment->fresh()->sync_status);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    #[DataProvider('writeFailures')]
    public function test_failed_delivery_preserves_the_saved_edit_and_can_be_retried(int $status): void
    {
        Queue::fake([SyncAppointment::class]);
        $appointment = Appointment::factory()->imported()->create()->fresh();
        $this->actingAs(User::findOrFail($appointment->user_id))->post('/appointments/'.$appointment->id.'/update', $this->data($appointment))->assertRedirect();
        Http::fake(['*/events/*' => Http::sequence()->push(EventPayload::for($appointment) + ['etag' => '"v1"'])->push([], $status)
            ->push(EventPayload::for($appointment) + ['etag' => '"v2"'])->push(['id' => $appointment->eventId()])]);
        $job = new SyncAppointment($appointment->id);
        $job->handle(app(GoogleCalendar::class));
        $this->assertSame('Updated appointment', $appointment->fresh()->title);
        $this->assertNotSame('synced', $appointment->fresh()->sync_status);
        $this->assertNotNull($appointment->fresh()->sync_error);
        $this->post('/appointments/'.$appointment->id.'/retry')->assertRedirect();
        $job->handle(app(GoogleCalendar::class));
        $this->assertSame('synced', $appointment->fresh()->sync_status);
        Queue::assertPushed(SyncAppointment::class);
    }

    public static function writeFailures(): array
    {
        return [[403], [412], [429], [503]];
    }

    public function test_read_only_appointments_remain_visible_but_cannot_be_changed_or_cancelled(): void
    {
        Queue::fake([SyncAppointment::class]);
        $appointment = Appointment::factory()->imported()->create()->fresh();
        $connection = $appointment->connection;
        $connection->update(['calendars' => [[...$connection->calendars[0], 'writable' => false]]]);
        $this->actingAs(User::findOrFail($appointment->user_id))->get('/appointments?date=2026-11-01&timezone=UTC')
            ->assertInertia(fn (Assert $page) => $page->where('appointments.0.writable', false));
        $this->post('/appointments/'.$appointment->id.'/update', $this->data($appointment))->assertForbidden();
        $this->post('/appointments/'.$appointment->id.'/cancel')->assertForbidden();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_imported_cancellation_is_saved_locally_and_google_failure_uses_the_same_retry_flow(): void
    {
        Queue::fake([SyncAppointment::class]);
        $appointment = Appointment::factory()->imported()->create()->fresh();
        $this->actingAs(User::findOrFail($appointment->user_id))->post('/appointments/'.$appointment->id.'/cancel')->assertRedirect();
        $this->assertSame('cancelled', $appointment->fresh()->status);
        $this->assertSame('pending', $appointment->fresh()->sync_status);
        Http::fake(['*/events/*' => Http::sequence()->push(EventPayload::for($appointment))->push([], 403)
            ->push(EventPayload::for($appointment))->push([], 204)]);
        $job = new SyncAppointment($appointment->id);
        $job->handle(app(GoogleCalendar::class));
        $this->assertSame('failed', $appointment->fresh()->sync_status);
        $this->post('/appointments/'.$appointment->id.'/retry')->assertRedirect();
        $job->handle(app(GoogleCalendar::class));
        $this->assertSame('synced', $appointment->fresh()->sync_status);
        $this->assertFalse($appointment->fresh()->holds_slot);
        Queue::assertPushed(SyncAppointment::class);
    }

    #[DataProvider('appointmentTypes')]
    public function test_google_patches_clear_the_previous_date_type_when_toggling_all_day(bool $allDay): void
    {
        $appointment = Appointment::factory()->imported()->create(['all_day' => $allDay, 'starts_at' => '2026-11-01 00:00:00', 'ends_at' => '2026-11-02 00:00:00'])->fresh();
        Http::fake(['*/events/*' => Http::sequence()->push(['id' => $appointment->eventId(), 'etag' => '"v1"', 'description' => 'Keep my notes'])
            ->push(['id' => $appointment->eventId()])]);
        app(GoogleCalendar::class)->save($appointment);
        Http::assertSent(function ($request) use ($allDay) {
            if ($request->method() !== 'PATCH') {
                return false;
            }
            $cleared = $allDay ? 'dateTime' : 'date';
            $kept = $allDay ? 'date' : 'dateTime';

            return array_key_exists($cleared, $request['start']) && $request['start'][$cleared] === null
                && array_key_exists($cleared, $request['end']) && $request['end'][$cleared] === null
                && isset($request['start'][$kept], $request['end'][$kept]) && ! isset($request['description']);
        });
    }

    public static function appointmentTypes(): array
    {
        return [[true], [false]];
    }

    public function test_customer_details_round_trip_without_duplicates_and_survive_missing_remote_metadata(): void
    {
        $sync = GoogleCalendarSync::factory()->create(['request_token' => fake()->uuid(), 'requested_at' => now()]);
        $appointment = Appointment::factory()->imported()->create(['calendar_connection_id' => $sync->calendar_connection_id, 'customer_name' => 'Sam', 'customer_email' => 'sam@example.com'])->fresh();
        $event = EventPayload::for($appointment);
        $withoutMetadata = $event;
        unset($withoutMetadata['extendedProperties']);
        $withoutMetadata['summary'] = 'Changed in Google';
        $withoutMetadata['start']['timeZone'] = 'America/New_York';
        $withoutMetadata['end']['timeZone'] = 'America/New_York';
        Http::fake(['*/events?*' => Http::sequence()->push(['items' => [$event], 'nextSyncToken' => 'one'])->push(['items' => [$withoutMetadata], 'nextSyncToken' => 'two'])]);
        (new SyncGoogleCalendar($sync->id, $sync->request_token))->handle(app(GoogleCalendar::class));
        $this->assertDatabaseCount('appointments', 1);
        $this->assertSame('Sam', $appointment->fresh()->customer_name);
        $sync->refresh()->update(['request_token' => fake()->uuid()]);
        (new SyncGoogleCalendar($sync->id, $sync->request_token))->handle(app(GoogleCalendar::class));
        $this->assertDatabaseCount('appointments', 1);
        $this->assertSame('Changed in Google', $appointment->fresh()->title);
        $this->assertSame('America/New_York', $appointment->fresh()->timezone);
        $this->assertSame('sam@example.com', $appointment->fresh()->customer_email);
    }

    public function test_all_day_validation_rejects_past_dates_and_reports_conflicts_on_the_visible_date_field(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 9, 24)->setTime(12, 0));
        Queue::fake([SyncAppointment::class]);
        $appointment = Appointment::factory()->local()->create(['starts_at' => '2026-11-02 10:00:00', 'ends_at' => '2026-11-02 11:00:00'])->fresh();
        $data = $this->data($appointment, ['request_key' => fake()->uuid(), 'all_day' => true, 'duration' => 1, 'timezone' => 'UTC']);
        $this->actingAs(User::findOrFail($appointment->user_id));
        $this->post('/appointments', [...$data, 'date' => '2026-09-23'])->assertSessionHasErrors('date');
        $this->post('/appointments', [...$data, 'duration' => 0])->assertSessionHasErrors('duration');
        $this->post('/appointments', $data)->assertSessionHasErrors('date');
        $other = Appointment::factory()->local()->create(['user_id' => $appointment->user_id, 'booking_calendar_id' => $appointment->booking_calendar_id, 'starts_at' => '2026-11-03 10:00:00', 'ends_at' => '2026-11-03 11:00:00'])->fresh();
        $this->post('/appointments/'.$other->id.'/update', [...$data, 'revision' => $other->revision()])->assertSessionHasErrors('date');
        $this->post('/appointments', [...$data, 'date' => '2026-09-24'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('appointments', 3);
        Queue::assertNothingPushed();
    }

    public function test_all_day_appointments_use_the_same_form_fields_and_preserve_dates_across_dst(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 9, 24));
        Queue::fake([SyncAppointment::class]);
        $connection = CalendarConnection::factory()->create();
        $data = ['request_key' => fake()->uuid(), 'calendar_id' => $connection->calendar->external_id,
            'title' => 'All-day booking', 'customer_name' => 'Sam', 'customer_email' => 'sam@example.com',
            'date' => '2026-11-01', 'timezone' => 'America/New_York', 'duration' => 2, 'all_day' => true];
        $this->actingAs(User::findOrFail($connection->user_id))->post('/appointments', $data)->assertRedirect()->assertSessionHasNoErrors();
        $appointment = Appointment::sole();
        $this->assertTrue($appointment->all_day);
        $this->assertSame('2026-11-01 04:00:00', $appointment->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-03 05:00:00', $appointment->ends_at->format('Y-m-d H:i:s'));
        $this->assertSame(['date' => '2026-11-01'], EventPayload::for($appointment)['start']);
        $this->assertSame(['date' => '2026-11-03'], EventPayload::for($appointment)['end']);
        $this->get('/appointments?date=2026-11-02&timezone=Pacific%2FHonolulu')->assertInertia(fn (Assert $page) => $page
            ->has('appointments', 1)->where('appointments.0.starts_at', '2026-11-01')->where('appointments.0.ends_at', '2026-11-03'));
        $this->get('/appointments?date=2026-11-03&timezone=UTC')->assertInertia(fn (Assert $page) => $page->has('appointments', 0));
        Queue::assertPushed(SyncAppointment::class);
    }
}
