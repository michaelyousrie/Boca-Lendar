<?php

namespace Tests\Feature\Concurrency;

use App\Actions\BookAppointment;
use App\Models\Appointment;
use App\Models\BookingCalendar;
use App\Models\CalendarConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class BookingRaceTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_real_processes_cannot_reserve_the_same_calendar_slot(): void
    {
        $first = CalendarConnection::factory()->create();
        $second = CalendarConnection::factory()->create(['selected_calendar_id' => $first->selected_calendar_id]);
        $results = $this->race([$first->user_id, $second->user_id], false);
        $statuses = array_column($results, 'status');
        sort($statuses);

        $this->assertSame(['rejected', 'reserved'], $statuses);
        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_duplicate_concurrent_requests_return_the_same_appointment(): void
    {
        $connection = CalendarConnection::factory()->create();
        $results = $this->race([$connection->user_id, $connection->user_id], true);

        $this->assertSame(['reserved', 'reserved'], array_column($results, 'status'));
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_replays_a_request_committed_after_its_initial_duplicate_lookup(): void
    {
        $connection = CalendarConnection::factory()->create();
        $user = User::findOrFail($connection->user_id);
        $booking = [
            'calendar_id' => $connection->calendar->external_id,
            'request_key' => (string) Str::uuid(),
            'title' => 'Concurrent booking', 'customer_name' => 'Sam', 'customer_email' => 'sam@example.com',
            'date' => now()->addDay()->toDateString(), 'start_time' => '12:00', 'timezone' => 'UTC', 'duration' => 30,
        ];
        $payload = json_encode(['user_id' => $user->id, 'booking' => $booking], JSON_THROW_ON_ERROR);
        $process = new Process([PHP_BINARY, base_path('tests/Fixtures/book.php'), $payload], base_path(), $this->environment());
        $process->setTimeout(10);
        $committedId = null;
        CalendarConnection::retrieved(function (CalendarConnection $retrieved) use ($connection, $process, &$committedId): void {
            if ($retrieved->id !== $connection->id || $committedId !== null) {
                return;
            }

            $process->run();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('reserved', $result['status']);
            $committedId = $result['id'];
        });

        $appointment = app(BookAppointment::class)->handle($user, $booking);

        $this->assertSame($committedId, $appointment->id);
        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_booking_waits_for_an_import_in_progress_before_checking_for_conflicts(): void
    {
        $connection = CalendarConnection::factory()->create();
        $calendar = $connection->calendar;
        $payload = json_encode(['user_id' => $connection->user_id, 'booking' => [
            'calendar_id' => $calendar->external_id,
            'request_key' => (string) Str::uuid(),
            'title' => 'Concurrent booking', 'customer_name' => 'Sam', 'customer_email' => 'sam@example.com',
            'date' => now('UTC')->addDay()->toDateString(), 'start_time' => '12:00', 'timezone' => 'UTC', 'duration' => 30,
        ]], JSON_THROW_ON_ERROR);
        $process = new Process([PHP_BINARY, base_path('tests/Fixtures/book.php'), $payload], base_path(), $this->environment());
        $process->setTimeout(10);

        DB::beginTransaction();
        try {
            $calendar->newQuery()->whereKey($calendar->id)->lockForUpdate()->firstOrFail();
            Appointment::factory()->imported()->create([
                'calendar_connection_id' => $connection->id,
                'starts_at' => now('UTC')->addDay()->setTime(12, 0),
                'ends_at' => now('UTC')->addDay()->setTime(13, 0),
            ]);
            $parentPid = DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
            $process->start();
            $deadline = microtime(true) + 5;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $blocked = DB::selectOne('SELECT count(*) AS total FROM pg_stat_activity WHERE ? = ANY(pg_blocking_pids(pid))', [$parentPid])->total;
                if ($blocked > 0) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertGreaterThan(0, $blocked, 'Booking must reach the calendar lock before the import commits. '.$process->getOutput().' '.$process->getErrorOutput());
            DB::commit();
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertSame('rejected', json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status']);
            $this->assertDatabaseCount('appointments', 1);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($process->isRunning()) {
                $process->stop();
            }
        }
    }

    private function race(array $userIds, bool $sameRequest): array
    {
        $processes = [];
        $requestKey = (string) Str::uuid();
        $environment = $this->environment();
        $calendar = CalendarConnection::where('user_id', $userIds[0])->firstOrFail()->calendar;

        DB::beginTransaction();
        try {
            BookingCalendar::whereKey($calendar->id)->lockForUpdate()->firstOrFail();
            foreach ($userIds as $userId) {
                $payload = json_encode(['user_id' => $userId, 'booking' => [
                    'calendar_id' => CalendarConnection::where('user_id', $userId)->firstOrFail()->calendar->external_id,
                    'request_key' => $sameRequest ? $requestKey : (string) Str::uuid(),
                    'title' => 'Concurrent booking', 'customer_name' => 'Sam', 'customer_email' => 'sam@example.com',
                    'date' => now()->addDay()->toDateString(), 'start_time' => '12:00', 'timezone' => 'UTC', 'duration' => 30,
                ]], JSON_THROW_ON_ERROR);
                $process = new Process([PHP_BINARY, base_path('tests/Fixtures/book.php'), $payload], base_path(), $environment);
                $process->setTimeout(10)->start();
                $processes[] = $process;
            }

            $deadline = microtime(true) + 5;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = DB::selectOne("SELECT count(*) AS total FROM pg_stat_activity WHERE datname = current_database() AND wait_event_type = 'Lock' AND pid <> pg_backend_pid()")->total;
                if ($waiting === 2) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $waiting, 'Both worker processes must reach the calendar lock.');
            DB::commit();

            return array_map(function (Process $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

                $this->assertJson($process->getOutput(), $process->getOutput());

                return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }, $processes);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }

    private function environment(): array
    {
        return [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '',
            'DB_HOST' => config('database.connections.pgsql.host'),
            'DB_PORT' => config('database.connections.pgsql.port'),
            'DB_DATABASE' => config('database.connections.pgsql.database'),
            'DB_USERNAME' => config('database.connections.pgsql.username'),
            'DB_PASSWORD' => config('database.connections.pgsql.password'),
        ];
    }
}
