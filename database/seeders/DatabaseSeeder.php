<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\BookingCalendar;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Ramsey\Uuid\Uuid;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }
        $user = User::firstOrCreate(['email' => 'demo@example.com'], ['name' => 'Alex Morgan', 'password' => 'schedule-demo']);
        $calendar = BookingCalendar::localFor($user);
        $day = CarbonImmutable::today('Africa/Cairo');
        $examples = [
            ['09:30', 45, 'Brand discovery session', 'Emma Wilson', 'emma@example.com'],
            ['11:00', 60, 'Website design review', 'Oliver Chen', 'oliver@example.com'],
            ['14:00', 30, 'A conversation about what’s next', 'Sofia Patel', 'sofia@example.com'],
        ];
        foreach ($examples as [$time, $duration, $title, $name, $email]) {
            $startsAt = $day->setTimeFromTimeString($time)->utc();
            Appointment::firstOrCreate([
                'user_id' => $user->id,
                'request_key' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'boca-demo/'.$day->toDateString().'/'.$time)->toString(),
            ], [
                'request_hash' => hash('sha256', $title), 'calendar_connection_id' => null,
                'booking_calendar_id' => $calendar->id, 'title' => $title, 'customer_name' => $name,
                'customer_email' => $email, 'timezone' => 'Africa/Cairo', 'starts_at' => $startsAt,
                'ends_at' => $startsAt->addMinutes($duration), 'sync_status' => 'local',
            ]);
        }
    }
}
