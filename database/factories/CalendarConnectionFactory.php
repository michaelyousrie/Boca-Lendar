<?php

namespace Database\Factories;

use App\Models\BookingCalendar;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CalendarConnectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(), 'provider' => 'google', 'account_id' => fake()->uuid(),
            'access_token' => 'test-access', 'refresh_token' => 'test-refresh', 'expires_at' => now()->addHour(),
            'email' => fake()->safeEmail(), 'selected_calendar_id' => BookingCalendar::factory(),
            'calendars' => function (array $attributes) {
                $calendar = BookingCalendar::find($attributes['selected_calendar_id']);

                return $calendar ? [[
                    'id' => $calendar->external_id, 'name' => $calendar->name,
                    'timezone' => $calendar->timezone, 'writable' => true,
                ]] : [];
            },
        ];
    }
}
