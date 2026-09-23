<?php

namespace App\Calendar;

use App\Models\Appointment;
use App\Models\CalendarConnection;
use Carbon\CarbonImmutable;

interface CalendarProvider
{
    public function calendars(CalendarConnection $connection): array;

    public function changes(CalendarConnection $connection, string $calendarId, ?string $syncToken, CarbonImmutable $start, CarbonImmutable $end): array;

    public function save(Appointment $appointment): void;

    public function delete(Appointment $appointment): void;

    public function event(Appointment $appointment): ?array;
}
