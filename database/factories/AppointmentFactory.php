<?php

namespace Database\Factories;

use App\Models\BookingCalendar;
use App\Models\CalendarConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AppointmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'calendar_connection_id' => CalendarConnection::factory(),
            'user_id' => fn (array $attributes) => CalendarConnection::findOrFail($attributes['calendar_connection_id'])->user_id,
            'booking_calendar_id' => fn (array $attributes) => CalendarConnection::findOrFail($attributes['calendar_connection_id'])->selected_calendar_id,
            'request_key' => fake()->uuid(), 'request_hash' => hash('sha256', 'test'),
            'title' => 'Design consultation', 'customer_name' => 'Alex Morgan', 'customer_email' => 'alex@example.com',
            'timezone' => 'UTC', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(),
            'next_sync_at' => now(),
        ];
    }

    public function imported(): static
    {
        return $this->state(fn () => [
            'google_event_id' => str_replace('-', '', fake()->uuid()), 'sync_status' => 'synced',
            'synced_at' => now(), 'next_sync_at' => null, 'holds_slot' => false, 'conflict_checked' => false,
            'request_key' => null, 'request_hash' => null, 'customer_name' => null, 'customer_email' => null,
            'starts_at' => '2026-11-01 10:00:00', 'ends_at' => '2026-11-01 11:00:00',
        ]);
    }

    public function local(): static
    {
        return $this->state(fn () => [
            'user_id' => User::factory(), 'calendar_connection_id' => null,
            'booking_calendar_id' => fn (array $attributes) => BookingCalendar::localFor(User::findOrFail($attributes['user_id']))->id,
            'sync_status' => 'local', 'next_sync_at' => null,
        ]);
    }
}
