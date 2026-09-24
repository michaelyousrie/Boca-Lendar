<?php

namespace Database\Factories;

use App\Models\CalendarConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

class GoogleCalendarSyncFactory extends Factory
{
    public function definition(): array
    {
        return [
            'calendar_connection_id' => CalendarConnection::factory(),
            'calendar_id' => fn (array $attributes) => CalendarConnection::findOrFail($attributes['calendar_connection_id'])->calendars[0]['id'],
            'timezone' => 'UTC', 'import_year' => now()->year + 2, 'sync_token' => 'saved-token', 'synced_at' => now(),
        ];
    }
}
