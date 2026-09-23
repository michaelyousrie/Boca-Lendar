<?php

namespace Tests\Feature\Actions;

use App\Actions\BookAppointment;
use App\Models\CalendarConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookAppointmentTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function input(?CalendarConnection $connection, array $changes = []): array
    {
        return array_replace([
            'calendar_id' => $connection?->calendar?->external_id ?? 'missing',
            'request_key' => (string) Str::uuid(), 'title' => 'Consultation', 'customer_name' => 'Alex',
            'customer_email' => 'alex@example.com', 'date' => '2026-10-12', 'start_time' => '10:00',
            'timezone' => 'Africa/Cairo', 'duration' => 60,
        ], $changes);
    }

    public function test_reserves_utc_time_and_records_pending_sync(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 23));
        $connection = CalendarConnection::factory()->create();

        $appointment = app(BookAppointment::class)->handle(User::find($connection->user_id), $this->input($connection));

        $this->assertSame('2026-10-12 07:00', $appointment->starts_at->format('Y-m-d H:i'));
        $this->assertSame('2026-10-12 08:00', $appointment->ends_at->format('Y-m-d H:i'));
        $this->assertDatabaseHas('appointments', ['id' => $appointment->id, 'sync_status' => 'pending', 'holds_slot' => true]);
    }

    #[DataProvider('overlappingSlots')]
    public function test_rejects_overlapping_reservations_across_users(string $time, int $duration): void
    {
        $this->travelTo(now()->setDate(2026, 9, 23));
        $first = CalendarConnection::factory()->create();
        $second = CalendarConnection::factory()->create(['selected_calendar_id' => $first->selected_calendar_id]);
        app(BookAppointment::class)->handle(User::find($first->user_id), $this->input($first));

        try {
            app(BookAppointment::class)->handle(User::find($second->user_id), $this->input($second, ['start_time' => $time, 'duration' => $duration]));
            $this->fail('The overlapping reservation was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('start_time', $exception->errors());
        }
        $this->assertDatabaseCount('appointments', 1);
    }

    public static function overlappingSlots(): array
    {
        return ['exact' => ['10:00', 60], 'starts inside' => ['10:30', 60], 'ends inside' => ['09:30', 60], 'contains' => ['09:00', 180], 'contained' => ['10:15', 15]];
    }

    public function test_allows_adjacent_slots_and_simultaneous_slots_on_another_calendar(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 23));
        $first = CalendarConnection::factory()->create();
        $second = CalendarConnection::factory()->create();
        $action = app(BookAppointment::class);
        $action->handle(User::find($first->user_id), $this->input($first));
        $action->handle(User::find($first->user_id), $this->input($first, ['start_time' => '11:00']));
        $action->handle(User::find($second->user_id), $this->input($second));

        $this->assertDatabaseCount('appointments', 3);
    }

    public function test_replays_a_request_without_duplicating_the_booking(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 23));
        $connection = CalendarConnection::factory()->create();
        $user = User::find($connection->user_id);
        $input = $this->input($connection);
        $action = app(BookAppointment::class);
        $first = $action->handle($user, $input);
        $second = $action->handle($user, $input);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_rejects_reusing_a_request_key_for_different_details(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 23));
        $connection = CalendarConnection::factory()->create();
        $user = User::find($connection->user_id);
        $input = $this->input($connection);
        app(BookAppointment::class)->handle($user, $input);
        $this->expectException(ValidationException::class);
        app(BookAppointment::class)->handle($user, array_replace($input, ['title' => 'Different']));
    }

    #[DataProvider('unavailableConnections')]
    public function test_requires_an_available_calendar_and_usable_connection(?array $state): void
    {
        $user = User::factory()->create();
        $connection = null;
        if ($state !== null) {
            $connection = CalendarConnection::factory()->create(['user_id' => $user->id, ...$state]);
        }
        $this->expectException(ValidationException::class);
        app(BookAppointment::class)->handle($user, $this->input($connection));
    }

    public static function unavailableConnections(): array
    {
        return ['disconnected' => [null], 'unselected' => [['selected_calendar_id' => null]]];
    }

    #[DataProvider('outOfRangeDates')]
    public function test_rejects_past_dates_and_dates_more_than_a_year_away(string $date): void
    {
        $this->travelTo(now()->setDate(2026, 9, 23));
        $connection = CalendarConnection::factory()->create();
        $this->expectException(ValidationException::class);
        app(BookAppointment::class)->handle(User::find($connection->user_id), $this->input($connection, ['date' => $date]));
    }

    public static function outOfRangeDates(): array
    {
        return [['2026-09-22'], ['2027-09-24']];
    }
}
