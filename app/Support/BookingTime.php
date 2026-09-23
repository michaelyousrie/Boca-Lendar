<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

class BookingTime
{
    public static function period(array $data): array
    {
        $allDay = (bool) ($data['all_day'] ?? false);
        $start = $allDay ? CarbonImmutable::parse($data['date'], $data['timezone'])->startOfDay()
            : self::parse($data['date'], $data['start_time'], $data['timezone']);
        if ($allDay ? $start->lessThan(now($data['timezone'])->startOfDay()) : $start->lessThanOrEqualTo(now())) {
            throw ValidationException::withMessages([$allDay ? 'date' : 'start_time' => 'Choose a future time.']);
        }
        if ($start->greaterThan(now()->addYear())) {
            throw ValidationException::withMessages(['date' => 'Choose a date within the next year.']);
        }
        $end = $allDay ? $start->addDays((int) $data['duration']) : $start->addMinutes((int) $data['duration']);

        return [$start->utc(), $end->utc()];
    }

    public static function parse(string $date, string $time, string $timezone): CarbonImmutable
    {
        $local = "$date $time";
        $wall = CarbonImmutable::createFromFormat('!Y-m-d H:i', $local, 'UTC');
        $zone = new DateTimeZone($timezone);
        $transitions = $zone->getTransitions($wall->timestamp - 172800, $wall->timestamp + 172800);
        $offsets = array_unique(array_column($transitions, 'offset'));
        $matches = [];

        // Round-trip every possible offset to detect both skipped and repeated DST times.
        foreach ($offsets as $offset) {
            $candidate = $wall->subSeconds($offset);
            if ($candidate->setTimezone($zone)->format('Y-m-d H:i') === $local) {
                $matches[] = $candidate;
            }
        }

        if (count($matches) !== 1) {
            throw ValidationException::withMessages(['start_time' => count($matches) === 0
                ? 'This time does not exist because the clocks move forward. Choose another time.'
                : 'This time occurs twice because the clocks move back. Choose UTC to specify the exact time.']);
        }

        return $matches[0];
    }
}
