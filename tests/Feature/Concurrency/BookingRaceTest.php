<?php

namespace Tests\Feature\Concurrency;

use App\Models\CalendarConnection;
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

    private function race(array $userIds, bool $sameRequest): array
    {
        DB::unprepared('CREATE FUNCTION hold_test_booking() RETURNS trigger AS $$ BEGIN PERFORM pg_advisory_xact_lock_shared(95023); RETURN NEW; END; $$ LANGUAGE plpgsql');
        DB::unprepared('CREATE TRIGGER hold_test_booking BEFORE INSERT ON appointments FOR EACH ROW EXECUTE FUNCTION hold_test_booking()');
        DB::select('SELECT pg_advisory_lock(95023)');
        $processes = [];
        $requestKey = (string) Str::uuid();
        $environment = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '',
            'DB_HOST' => config('database.connections.pgsql.host'),
            'DB_PORT' => config('database.connections.pgsql.port'),
            'DB_DATABASE' => config('database.connections.pgsql.database'),
            'DB_USERNAME' => config('database.connections.pgsql.username'),
            'DB_PASSWORD' => config('database.connections.pgsql.password'),
        ];

        try {
            foreach ($userIds as $userId) {
                $process = new Process([PHP_BINARY, base_path('tests/Fixtures/book.php')], base_path(), $environment);
                $process->setInput(json_encode(['user_id' => $userId, 'booking' => [
                    'calendar_id' => CalendarConnection::where('user_id', $userId)->firstOrFail()->calendar->external_id,
                    'request_key' => $sameRequest ? $requestKey : (string) Str::uuid(),
                    'title' => 'Concurrent booking', 'customer_name' => 'Sam', 'customer_email' => 'sam@example.com',
                    'date' => now()->addDay()->toDateString(), 'start_time' => '12:00', 'timezone' => 'UTC', 'duration' => 30,
                ]], JSON_THROW_ON_ERROR));
                $process->setTimeout(10)->start();
                $processes[] = $process;
            }

            // Both inserts must reach the database before either is allowed to finish.
            $deadline = microtime(true) + 5;
            do {
                $waiting = DB::selectOne("SELECT count(*) AS total FROM pg_locks WHERE locktype = 'advisory' AND objid = 95023 AND NOT granted")->total;
                if ($waiting === 2) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $waiting, 'Both worker processes must reach the insert barrier.');
            DB::select('SELECT pg_advisory_unlock(95023)');

            return array_map(function (Process $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

                $this->assertJson($process->getOutput(), $process->getOutput());

                return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }, $processes);
        } finally {
            DB::select('SELECT pg_advisory_unlock(95023)');
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            DB::unprepared('DROP FUNCTION hold_test_booking() CASCADE');
        }
    }
}
