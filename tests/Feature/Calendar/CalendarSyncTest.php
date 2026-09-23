<?php

namespace Tests\Feature\Calendar;

use App\Calendar\CalendarSync;
use App\Jobs\SyncGoogleCalendar;
use App\Models\Appointment;
use App\Models\CalendarConnection;
use App\Models\GoogleCalendarSync;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CalendarSyncTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_navigation_only_reads_saved_appointments_without_dispatching_or_contacting_google(): void
    {
        Queue::fake();
        $sync = GoogleCalendarSync::factory()->create();
        Appointment::factory()->imported()->create(['calendar_connection_id' => $sync->calendar_connection_id]);
        $this->actingAs(User::findOrFail($sync->connection->user_id));
        $this->get('/appointments?date=2026-11-01&timezone=UTC')->assertInertia(fn (Assert $page) => $page
            ->has('appointments', 1)->where('calendarSync.'.$sync->calendar_id.'.refreshing', false)->missing('googleEvents'));
        $this->get('/appointments?date=2026-12-15&timezone=UTC')->assertOk();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_refreshes_are_coalesced_and_stuck_requests_can_be_replaced(): void
    {
        Queue::fake();
        $connection = CalendarConnection::factory()->create();
        $store = app(CalendarSync::class);
        $first = $store->requestSync($connection, $connection->calendars[0]);
        $again = $store->requestSync($connection, $connection->calendars[0]);
        $this->assertSame($first->request_token, $again->request_token);
        Queue::assertPushed(SyncGoogleCalendar::class, 1);
        $first->update(['requested_at' => now()->subMinutes(3)]);
        $this->assertSame('Google sync timed out. Try again.', $store->status($connection)[$first->calendar_id]['error']);
        $next = $store->requestSync($connection, $connection->calendars[0]);
        $this->assertNotSame($first->request_token, $next->request_token);
        Queue::assertPushed(SyncGoogleCalendar::class, 2);
    }

    public function test_a_changed_calendar_timezone_requires_a_new_initial_import(): void
    {
        Queue::fake();
        $sync = GoogleCalendarSync::factory()->create(['timezone' => 'UTC']);
        $calendar = [...$sync->connection->calendars[0], 'timezone' => 'Africa/Cairo'];
        $updated = app(CalendarSync::class)->requestSync($sync->connection, $calendar);
        $this->assertNull($updated->sync_token);
        $this->assertSame('Africa/Cairo', $updated->timezone);
        Queue::assertPushed(SyncGoogleCalendar::class, 1);
    }

    public function test_reconnection_preserves_appointments_and_missing_connections_or_calendars_have_no_sync_status(): void
    {
        Queue::fake();
        $sync = GoogleCalendarSync::factory()->create();
        $appointment = Appointment::factory()->imported()->create(['calendar_connection_id' => $sync->calendar_connection_id]);
        $sync->connection->update(['needs_reconnect' => true]);
        $store = app(CalendarSync::class);
        $store->requestSync($sync->connection, $sync->connection->calendars[0]);
        $this->assertSame([], $store->status(null));
        $this->assertSame('Reconnect Google to update appointments.', $store->status($sync->connection)[$sync->calendar_id]['error']);
        $sync->connection->update(['calendars' => []]);
        $this->assertSame([], $store->status($sync->connection));
        $this->assertModelExists($appointment);
        Queue::assertNothingPushed();
    }
}
