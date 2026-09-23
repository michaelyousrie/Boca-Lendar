<?php

namespace Tests\Unit\Support;

use App\Support\BookingTime;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookingTimeTest extends TestCase
{
    #[DataProvider('validTimes')]
    public function test_converts_local_time_to_the_correct_utc_instant(string $date, string $time, string $zone, string $utc): void
    {
        $this->assertSame($utc, BookingTime::parse($date, $time, $zone)->format('Y-m-d H:i'));
    }

    public static function validTimes(): array
    {
        return [
            'UTC' => ['2026-10-12', '10:00', 'UTC', '2026-10-12 10:00'],
            'Cairo summer' => ['2026-10-12', '10:00', 'Africa/Cairo', '2026-10-12 07:00'],
            'Cairo winter' => ['2026-12-12', '10:00', 'Africa/Cairo', '2026-12-12 08:00'],
            'fractional offset' => ['2026-10-12', '00:15', 'Asia/Kathmandu', '2026-10-11 18:30'],
            'leap day' => ['2028-02-29', '10:00', 'UTC', '2028-02-29 10:00'],
        ];
    }

    #[DataProvider('dstTransitions')]
    public function test_rejects_nonexistent_and_ambiguous_wall_times(string $date, string $time, string $zone, string $message): void
    {
        try {
            BookingTime::parse($date, $time, $zone);
            $this->fail('The ambiguous or missing local time was accepted.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($message, $exception->errors()['start_time'][0]);
        }
    }

    public static function dstTransitions(): array
    {
        return [
            'spring gap' => ['2026-03-08', '02:30', 'America/New_York', 'does not exist'],
            'autumn repeat' => ['2026-11-01', '01:30', 'America/New_York', 'occurs twice'],
            'half-hour repeat' => ['2026-04-05', '01:45', 'Australia/Lord_Howe', 'occurs twice'],
        ];
    }
}
