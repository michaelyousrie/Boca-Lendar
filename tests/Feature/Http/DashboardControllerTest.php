<?php

namespace Tests\Feature\Http;

use App\Models\Appointment;
use App\Models\CalendarConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_shows_an_empty_workspace_before_calendar_connection(): void
    {
        $this->actingAs(User::factory()->create())->get('/appointments')->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')->where('connection', null)->has('appointments', 0)->has('timezones')->has('syncIssues', 0)->has('appointmentDates', 0)->missing('demoEvents'));
    }

    public function test_shows_only_owned_appointments_and_never_exposes_calendar_credentials(): void
    {
        $connection = CalendarConnection::factory()->create();
        $own = Appointment::factory()->create(['calendar_connection_id' => $connection->id, 'starts_at' => '2026-10-12 07:00:00', 'ends_at' => '2026-10-12 08:00:00']);
        Appointment::factory()->create(['starts_at' => '2026-10-12 07:00:00', 'ends_at' => '2026-10-12 08:00:00']);
        $this->actingAs(User::find($connection->user_id))->get('/appointments?date=2026-10-12&timezone=Africa%2FCairo')
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard')->has('appointments', 1)
                ->where('appointments.0.id', $own->id)->missing('connection.access_token')->missing('connection.refresh_token')->missing('auth.user.password'));
    }

    public function test_includes_overnight_appointments_and_excludes_exact_day_boundaries(): void
    {
        $connection = CalendarConnection::factory()->create();
        $overnight = Appointment::factory()->create(['calendar_connection_id' => $connection->id, 'starts_at' => '2026-10-11 23:30:00', 'ends_at' => '2026-10-12 00:30:00']);
        Appointment::factory()->create(['calendar_connection_id' => $connection->id, 'starts_at' => '2026-10-11 22:30:00', 'ends_at' => '2026-10-11 23:30:00']);
        Appointment::factory()->create(['calendar_connection_id' => $connection->id, 'starts_at' => '2026-10-13 00:00:00', 'ends_at' => '2026-10-13 01:00:00']);
        $this->actingAs(User::find($connection->user_id))->get('/appointments?date=2026-10-12&timezone=UTC')
            ->assertInertia(fn (Assert $page) => $page->has('appointments', 1)->where('appointments.0.id', $overnight->id)->has('syncIssues', 3));
    }

    public function test_rejects_invalid_date_and_timezone_filters(): void
    {
        $this->actingAs(User::factory()->create())->get('/appointments?date=bad&timezone=bad&month=2026-13')->assertSessionHasErrors(['date', 'timezone', 'month']);
    }

    #[DataProvider('partialFlashRequests')]
    public function test_partial_requests_clear_expired_flashes_and_deliver_new_ones(string $key, string $props): void
    {
        $this->actingAs(User::factory()->create());
        session()->flash($key, 'Initial message');
        session()->save();
        $this->get('/appointments')->assertInertia(fn (Assert $page) => $page->where('flash.'.$key, 'Initial message'));
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion(),
            'X-Inertia-Partial-Component' => 'Dashboard', 'X-Inertia-Partial-Data' => $props];

        $this->get('/appointments', $headers)->assertJsonPath('props.flash', ['success' => null, 'error' => null]);

        session()->flash($key, 'New message');
        session()->save();
        $this->get('/appointments', $headers)->assertJsonPath('props.flash.'.$key, 'New message');
    }

    public static function partialFlashRequests(): array
    {
        return [
            'success after polling' => ['success', 'appointments,connection,syncIssues,appointmentDates'],
            'error after polling' => ['error', 'appointments,connection,syncIssues,appointmentDates'],
            'success after month navigation' => ['success', 'month,appointmentDates'],
            'error after month navigation' => ['error', 'month,appointmentDates'],
        ];
    }

    public function test_month_markers_include_only_owned_active_dates_once_and_leave_the_selected_day_unchanged(): void
    {
        $user = User::factory()->create();
        foreach (['09:00:00', '14:00:00'] as $time) {
            Appointment::factory()->local()->create(['user_id' => $user->id, 'starts_at' => '2026-11-12 '.$time, 'ends_at' => '2026-11-12 '.substr($time, 0, 2).':30:00']);
        }
        Appointment::factory()->local()->create(['user_id' => $user->id, 'starts_at' => '2026-11-02 09:00:00', 'ends_at' => '2026-11-02 10:00:00']);
        Appointment::factory()->local()->create(['starts_at' => '2026-11-03 09:00:00', 'ends_at' => '2026-11-03 10:00:00']);
        foreach (['local', 'pending', 'failed', 'synced'] as $index => $syncStatus) {
            Appointment::factory()->local()->create(['user_id' => $user->id, 'status' => 'cancelled', 'sync_status' => $syncStatus,
                'starts_at' => '2026-11-'.(20 + $index).' 09:00:00', 'ends_at' => '2026-11-'.(20 + $index).' 10:00:00']);
        }

        $this->actingAs($user)->get('/appointments?date=2026-10-12&month=2026-11&timezone=UTC')
            ->assertInertia(fn (Assert $page) => $page->where('date', '2026-10-12')->where('month', '2026-11')
                ->has('appointments', 0)->where('appointmentDates', ['2026-11-02', '2026-11-12']));
    }

    #[DataProvider('markerBoundaries')]
    public function test_month_markers_follow_display_timezone_and_exclusive_end_times(string $start, string $end, string $timezone, string $month, array $dates): void
    {
        $appointment = Appointment::factory()->local()->create(['starts_at' => $start, 'ends_at' => $end]);

        $this->actingAs(User::findOrFail($appointment->user_id))->get('/appointments?'.http_build_query(['date' => $month.'-01', 'timezone' => $timezone]))
            ->assertInertia(fn (Assert $page) => $page->where('month', $month)->where('appointmentDates', $dates));
    }

    public static function markerBoundaries(): array
    {
        return [
            'midnight end' => ['2026-10-12 23:00:00', '2026-10-13 00:00:00', 'UTC', '2026-10', ['2026-10-12']],
            'overnight' => ['2026-10-12 23:30:00', '2026-10-13 00:30:00', 'UTC', '2026-10', ['2026-10-12', '2026-10-13']],
            'crosses month start' => ['2026-09-30 23:30:00', '2026-10-01 00:30:00', 'UTC', '2026-10', ['2026-10-01']],
            'crosses month end' => ['2026-10-31 23:30:00', '2026-11-01 00:30:00', 'UTC', '2026-10', ['2026-10-31']],
            'ends at month start' => ['2026-09-30 23:00:00', '2026-10-01 00:00:00', 'UTC', '2026-10', []],
            'starts at month end' => ['2026-11-01 00:00:00', '2026-11-01 01:00:00', 'UTC', '2026-10', []],
            'leap day' => ['2028-02-28 23:30:00', '2028-02-29 00:30:00', 'UTC', '2028-02', ['2028-02-28', '2028-02-29']],
            'timezone crosses year' => ['2026-12-31 22:30:00', '2027-01-01 00:30:00', 'Africa/Cairo', '2027-01', ['2027-01-01']],
            'spring forward' => ['2026-03-08 04:30:00', '2026-03-08 07:30:00', 'America/New_York', '2026-03', ['2026-03-07', '2026-03-08']],
            'fall back' => ['2026-11-01 03:30:00', '2026-11-01 07:30:00', 'America/New_York', '2026-11', ['2026-11-01']],
        ];
    }

    public function test_sync_sidebar_includes_only_owned_pending_and_failed_appointments_across_dates(): void
    {
        config(['services.google.client_id' => 'id', 'services.google.client_secret' => 'secret']);
        $connection = CalendarConnection::factory()->create();
        $failed = Appointment::factory()->create(['calendar_connection_id' => $connection->id, 'starts_at' => '2026-10-12 22:30:00', 'ends_at' => '2026-10-12 23:00:00', 'sync_status' => 'failed', 'sync_error' => 'Reconnect Google.']);
        $pending = Appointment::factory()->create(['calendar_connection_id' => $connection->id, 'starts_at' => '2026-10-14 12:00:00', 'ends_at' => '2026-10-14 13:00:00', 'status' => 'cancelled']);
        Appointment::factory()->local()->create(['user_id' => $connection->user_id]);
        Appointment::factory()->create(['calendar_connection_id' => $connection->id, 'sync_status' => 'synced']);
        Appointment::factory()->create(['sync_status' => 'failed', 'sync_error' => 'Another user error.']);

        $this->actingAs(User::find($connection->user_id))->get('/appointments?date=2026-10-10&timezone=Africa%2FCairo')
            ->assertInertia(fn (Assert $page) => $page->has('appointments', 0)->has('syncIssues', 2)
                ->where('syncIssues.0.id', $failed->id)->where('syncIssues.0.date', '2026-10-13')
                ->where('syncIssues.0.sync_error', 'Reconnect Google.')->where('syncIssues.1.id', $pending->id)
                ->where('googleConfigured', true)->missing('demoEvents'));
    }
}
