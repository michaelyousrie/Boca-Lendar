<?php

namespace Tests\Feature\Http;

use App\Models\Appointment;
use App\Models\BookingCalendar;
use App\Models\CalendarConnection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FutureAppointmentsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function input(string $date = '2026-09-23', string $time = '12:01', string $timezone = 'UTC'): array
    {
        return [
            'request_key' => (string) Str::uuid(), 'title' => 'Consultation', 'customer_name' => 'Sam',
            'customer_email' => 'sam@example.com', 'date' => $date, 'start_time' => $time,
            'timezone' => $timezone, 'duration' => 30,
        ];
    }

    #[DataProvider('pastTimes')]
    public function test_rejects_past_or_current_times_even_when_browser_validation_is_bypassed(string $now, string $date, string $time, string $timezone, bool $google): void
    {
        $this->travelTo(CarbonImmutable::parse($now));
        $user = User::factory()->create();
        $input = $this->input($date, $time, $timezone);
        if ($google) {
            $connection = CalendarConnection::factory()->create(['user_id' => $user->id]);
            $input['calendar_id'] = $connection->calendar->external_id;
        }
        Queue::fake();

        $this->actingAs($user)->post('/appointments', $input)
            ->assertSessionHasErrors(['start_time' => 'Choose a future time.']);

        $this->assertDatabaseCount('appointments', 0);
        Queue::assertNothingPushed();
    }

    public static function pastTimes(): array
    {
        return [
            'previous day' => ['2026-09-23T12:00:00Z', '2026-09-22', '23:59', 'UTC', false],
            'earlier today' => ['2026-09-23T12:00:00Z', '2026-09-23', '10:00', 'UTC', false],
            'exactly now' => ['2026-09-23T12:00:00Z', '2026-09-23', '12:00', 'UTC', false],
            'current minute elapsed' => ['2026-09-23T12:00:01Z', '2026-09-23', '12:00', 'UTC', false],
            'ahead of UTC' => ['2026-09-23T12:00:00Z', '2026-09-23', '14:59', 'Africa/Cairo', false],
            'behind UTC' => ['2026-09-23T00:00:00Z', '2026-09-22', '16:59', 'America/Los_Angeles', false],
            'local midnight' => ['2026-09-23T21:00:00Z', '2026-09-24', '00:00', 'Africa/Cairo', false],
            'Google destination' => ['2026-09-23T12:00:00Z', '2026-09-23', '10:00', 'UTC', true],
        ];
    }

    public function test_accepts_the_next_minute_in_the_appointment_timezone(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-23T12:00:59Z'));
        Queue::fake();

        $this->actingAs(User::factory()->create())->post('/appointments', $this->input('2026-09-23', '15:01', 'Africa/Cairo'))
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-09-23 12:01:00', Appointment::sole()->starts_at->format('Y-m-d H:i:s'));
        Queue::assertNothingPushed();
    }

    public function test_rechecks_time_after_waiting_to_resolve_the_calendar(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-23T12:00:00Z'));
        BookingCalendar::created(fn () => $this->travel(2)->minutes());
        Queue::fake();

        $this->actingAs(User::factory()->create())->post('/appointments', $this->input())
            ->assertSessionHasErrors(['start_time' => 'Choose a future time.']);

        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('booking_calendars', 0);
        Queue::assertNothingPushed();
    }

    public function test_replaying_an_existing_booking_after_its_start_does_not_create_another(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-23T12:00:00Z'));
        $input = $this->input();
        Queue::fake();
        $this->actingAs(User::factory()->create())->post('/appointments', $input)->assertSessionHasNoErrors();
        $id = Appointment::sole()->id;
        $this->travel(2)->minutes();

        $this->post('/appointments', $input)->assertSessionHasNoErrors();

        $this->assertSame($id, Appointment::sole()->id);
        Queue::assertNothingPushed();
    }
}
