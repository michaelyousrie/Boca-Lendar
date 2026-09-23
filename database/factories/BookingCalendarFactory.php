<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class BookingCalendarFactory extends Factory
{
    public function definition(): array
    {
        return ['provider' => 'google', 'external_id' => fake()->uuid(), 'name' => 'Studio appointments', 'timezone' => 'Africa/Cairo'];
    }
}
