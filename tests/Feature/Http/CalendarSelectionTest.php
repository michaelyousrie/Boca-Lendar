<?php

namespace Tests\Feature\Http;

use App\Jobs\SyncAppointment;
use App\Models\Appointment;
use App\Models\BookingCalendar;
use App\Models\CalendarConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CalendarSelectionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function connect(): CalendarConnection
    {
        $connection = CalendarConnection::factory()->create(['selected_calendar_id' => null]);
        $connection->update(['calendars' => [
            ['id' => 'team', 'name' => 'Team', 'timezone' => 'UTC', 'writable' => true],
            ['id' => 'personal-'.$connection->user_id, 'name' => 'Personal', 'timezone' => 'UTC', 'writable' => true],
            ['id' => 'holidays', 'name' => 'Holidays', 'timezone' => 'UTC', 'writable' => false],
        ]]);
        Queue::fake();

        return $connection;
    }

    private function input(string $calendar = 'team', array $changes = []): array
    {
        return array_replace([
            'calendar_id' => $calendar, 'request_key' => (string) Str::uuid(), 'title' => 'Consultation',
            'customer_name' => 'Sam', 'customer_email' => 'sam@example.com',
            'date' => now()->addDay()->toDateString(), 'start_time' => '14:00', 'timezone' => 'UTC', 'duration' => 30,
        ], $changes);
    }

    public function test_first_booking_selects_and_remembers_its_calendar(): void
    {
        $connection = $this->connect();
        $this->actingAs(User::find($connection->user_id))->post('/appointments', $this->input())->assertSessionHasNoErrors();

        $appointment = Appointment::sole();
        $this->assertSame('team', $appointment->calendar->external_id);
        $this->assertSame($appointment->booking_calendar_id, $connection->fresh()->selected_calendar_id);
        Queue::assertPushed(SyncAppointment::class);
    }

    public function test_booking_rejects_a_slot_occupied_by_an_imported_google_event(): void
    {
        $connection = $this->connect();
        $otherConnection = $this->connect();
        $input = $this->input();
        $start = now('UTC')->addDay()->setTime(14, 15);
        $calendar = BookingCalendar::factory()->create(['external_id' => 'team', 'timezone' => 'UTC']);
        Appointment::factory()->imported()->create([
            'calendar_connection_id' => $otherConnection->id,
            'booking_calendar_id' => $calendar->id,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHour(),
        ]);

        $this->actingAs(User::findOrFail($connection->user_id))->post('/appointments', $input)
            ->assertSessionHasErrors('start_time');

        $this->assertDatabaseCount('appointments', 1);
        $this->assertNull($connection->fresh()->selected_calendar_id);
        Queue::assertNothingPushed();
    }

    public function test_booking_waits_for_an_imported_event_cancellation_to_finish_in_google(): void
    {
        $connection = $this->connect();
        $calendar = BookingCalendar::factory()->create(['external_id' => 'team', 'timezone' => 'UTC']);
        $start = now('UTC')->addDay()->setTime(14, 0);
        $imported = Appointment::factory()->imported()->create([
            'calendar_connection_id' => $connection->id,
            'booking_calendar_id' => $calendar->id,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHour(),
            'status' => 'cancelled',
            'sync_status' => 'pending',
        ]);

        $this->actingAs(User::findOrFail($connection->user_id))->post('/appointments', $this->input())
            ->assertSessionHasErrors('start_time');

        $this->assertDatabaseCount('appointments', 1);
        Queue::assertNothingPushed();

        $imported->update(['sync_status' => 'synced']);
        $this->post('/appointments', $this->input())->assertSessionHasNoErrors();
        $this->assertDatabaseCount('appointments', 2);
    }

    public function test_booking_can_follow_an_imported_event_without_overlapping_it(): void
    {
        $connection = $this->connect();
        $calendar = BookingCalendar::factory()->create(['external_id' => 'team', 'timezone' => 'UTC']);
        $start = now('UTC')->addDay()->setTime(13, 0);
        Appointment::factory()->imported()->create([
            'calendar_connection_id' => $connection->id,
            'booking_calendar_id' => $calendar->id,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHour(),
        ]);

        $this->actingAs(User::findOrFail($connection->user_id))->post('/appointments', $this->input())
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('appointments', 2);
        Queue::assertPushed(SyncAppointment::class);
    }

    public function test_each_form_keeps_its_destination_after_another_tab_changes_the_default(): void
    {
        $connection = $this->connect();
        $firstForm = $this->input('team');
        $personal = 'personal-'.$connection->user_id;
        $this->actingAs(User::find($connection->user_id))->post('/appointments', $this->input($personal))->assertSessionHasNoErrors();
        $existing = Appointment::sole();

        $this->post('/appointments', $firstForm)->assertSessionHasNoErrors();

        $this->assertSame($personal, $existing->fresh()->calendar->external_id);
        $this->assertSame('team', Appointment::where('request_key', $firstForm['request_key'])->sole()->calendar->external_id);
        $this->assertSame('team', $connection->fresh()->calendar->external_id);
        $this->assertDatabaseCount('appointments', 2);
    }

    #[DataProvider('invalidCalendars')]
    public function test_rejects_unavailable_or_read_only_calendars(string $calendar): void
    {
        $connection = $this->connect();
        $this->actingAs(User::find($connection->user_id))->post('/appointments', $this->input($calendar))->assertSessionHasErrors('calendar_id');
        $this->assertNull($connection->fresh()->selected_calendar_id);
        $this->assertDatabaseCount('appointments', 0);
        Queue::assertNothingPushed();
    }

    public static function invalidCalendars(): array
    {
        return ['read only' => ['holidays'], 'unknown' => ['forged-calendar']];
    }

    public function test_rejects_a_private_calendar_from_another_users_connection(): void
    {
        $own = $this->connect();
        $other = $this->connect();
        $this->actingAs(User::find($own->user_id))->post('/appointments', $this->input('personal-'.$other->user_id))->assertSessionHasErrors('calendar_id');
        $this->assertDatabaseCount('appointments', 0);
        Queue::assertNothingPushed();
    }

    public function test_shared_calendar_choices_resolve_to_one_booking_resource(): void
    {
        $first = $this->connect();
        $second = $this->connect();
        $this->actingAs(User::find($first->user_id))->post('/appointments', $this->input())->assertSessionHasNoErrors();
        $this->actingAs(User::find($second->user_id))->post('/appointments', $this->input('team', ['start_time' => '15:00']))->assertSessionHasNoErrors();
        $this->assertSame($first->fresh()->selected_calendar_id, $second->fresh()->selected_calendar_id);
        $this->assertDatabaseCount('booking_calendars', 1);
    }

    public function test_cannot_change_the_calendar_by_reusing_a_successful_request_key(): void
    {
        $connection = $this->connect();
        $input = $this->input();
        $this->actingAs(User::find($connection->user_id))->post('/appointments', $input)->assertSessionHasNoErrors();
        $this->post('/appointments', array_replace($input, ['calendar_id' => 'personal-'.$connection->user_id]))->assertSessionHasErrors('request_key');
        $this->assertDatabaseCount('appointments', 1);
        $this->assertSame('team', Appointment::sole()->calendar->external_id);
    }

    public function test_a_failed_booking_does_not_change_the_remembered_calendar(): void
    {
        $connection = $this->connect();
        $personal = 'personal-'.$connection->user_id;
        $this->actingAs(User::find($connection->user_id))->post('/appointments', $this->input())->assertSessionHasNoErrors();
        $this->post('/appointments', $this->input($personal))->assertSessionHasNoErrors();
        $this->post('/appointments', $this->input())->assertSessionHasErrors('start_time');

        $this->assertSame($personal, $connection->fresh()->calendar->external_id);
        $this->assertDatabaseCount('appointments', 2);
    }

    public function test_replaying_an_old_booking_does_not_reset_the_latest_default(): void
    {
        $connection = $this->connect();
        $input = $this->input();
        $personal = 'personal-'.$connection->user_id;
        $this->actingAs(User::find($connection->user_id))->post('/appointments', $input)->assertSessionHasNoErrors();
        $this->post('/appointments', $this->input($personal))->assertSessionHasNoErrors();
        $this->post('/appointments', $input)->assertSessionHasNoErrors();

        $this->assertSame($personal, $connection->fresh()->calendar->external_id);
        $this->assertDatabaseCount('appointments', 2);
    }
}
