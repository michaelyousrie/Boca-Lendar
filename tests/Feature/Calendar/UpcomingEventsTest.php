<?php

namespace Tests\Feature\Calendar;

use App\Calendar\UpcomingEvents;
use App\Models\Appointment;
use App\Models\GoogleCalendarSync;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UpcomingEventsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_next_three_events_mix_google_events_and_bookings_across_months(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 11, 1)->setTime(9, 0));
        $sync = GoogleCalendarSync::factory()->create();
        foreach ([['first', '2026-11-01'], ['third', '2026-12-01'], ['fourth', '2026-12-02']] as [$title, $date]) {
            Appointment::factory()->imported()->create(['calendar_connection_id' => $sync->calendar_connection_id, 'title' => $title, 'starts_at' => $date.' 10:00:00', 'ends_at' => $date.' 11:00:00']);
        }
        $booking = Appointment::factory()->create(['calendar_connection_id' => $sync->calendar_connection_id, 'starts_at' => '2026-11-02 10:00:00', 'ends_at' => '2026-11-02 11:00:00']);
        $events = app(UpcomingEvents::class)->forUser($sync->connection->user_id, 'UTC');
        $this->assertSame(['first', $booking->title, 'third'], array_column($events, 'title'));
        $this->assertSame($sync->calendar_id, $events[1]['calendar_id']);
        $this->actingAs(User::findOrFail($sync->connection->user_id))->get('/appointments?date=2026-11-01&timezone=UTC')
            ->assertInertia(fn (Assert $page) => $page->has('upcomingEvents', 3)->where('upcomingEvents.2.date', '2026-12-01'));
    }

    public function test_excludes_past_cancelled_and_other_users_bookings_without_a_google_account(): void
    {
        $user = User::factory()->create();
        Appointment::factory()->local()->create(['user_id' => $user->id, 'status' => 'cancelled']);
        Appointment::factory()->local()->create(['user_id' => $user->id, 'starts_at' => now()->subHour(), 'ends_at' => now()]);
        Appointment::factory()->local()->create();
        $this->assertSame([], app(UpcomingEvents::class)->forUser($user->id, 'UTC'));
    }

    public function test_all_day_dates_stay_local_and_timed_events_use_the_display_timezone(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 11, 1)->setTime(9, 0));
        $sync = GoogleCalendarSync::factory()->create();
        foreach ([
            ['title' => 'past', 'all_day' => true, 'starts_at' => '2026-10-31', 'ends_at' => '2026-11-01'],
            ['title' => 'today', 'all_day' => true, 'starts_at' => '2026-11-01', 'ends_at' => '2026-11-02'],
            ['title' => 'late', 'starts_at' => '2026-11-01 23:00:00', 'ends_at' => '2026-11-02 00:00:00'],
            ['title' => 'started', 'starts_at' => '2026-11-01 09:00:00', 'ends_at' => '2026-11-01 10:00:00'],
        ] as $event) {
            Appointment::factory()->imported()->create(['calendar_connection_id' => $sync->calendar_connection_id, ...$event]);
        }
        $events = app(UpcomingEvents::class)->forUser($sync->connection->user_id, 'Africa/Cairo');
        $this->assertSame(['today', 'late'], array_column($events, 'title'));
        $this->assertSame(['2026-11-01', '2026-11-02'], array_column($events, 'date'));
    }

    public function test_all_day_appointments_are_ranked_by_floating_date_before_limiting_results(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 10, 31));
        $sync = GoogleCalendarSync::factory()->create();
        foreach (range(1, 3) as $number) {
            Appointment::factory()->imported()->create(['calendar_connection_id' => $sync->calendar_connection_id, 'title' => 'Later '.$number, 'all_day' => true,
                'timezone' => 'Pacific/Kiritimati', 'starts_at' => '2026-11-01 10:00:00', 'ends_at' => '2026-11-02 10:00:00']);
        }
        Appointment::factory()->imported()->create(['calendar_connection_id' => $sync->calendar_connection_id, 'title' => 'Earlier', 'all_day' => true,
            'timezone' => 'Pacific/Pago_Pago', 'starts_at' => '2026-11-01 11:00:00', 'ends_at' => '2026-11-02 11:00:00']);
        $events = app(UpcomingEvents::class)->forUser($sync->connection->user_id, 'UTC');
        $this->assertCount(3, $events);
        $this->assertSame('Earlier', $events[0]['title']);
        $this->assertSame('2026-11-01', $events[0]['date']);
    }
}
