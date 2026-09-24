<?php

namespace Tests\Feature\Concurrency;

use App\Actions\BookAppointment;
use App\Models\Appointment;
use App\Models\CalendarConnection;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Tests\TestCase;

class DatabaseFailureTest extends TestCase
{
    use DatabaseMigrations;

    private function secondConnection(): void
    {
        config(['database.connections.racer' => config('database.connections.pgsql')]);
    }

    public function test_registration_reports_an_email_inserted_after_validation(): void
    {
        $this->secondConnection();
        User::creating(function (User $user) {
            DB::connection('racer')->table('users')->insert($user->getAttributes());
        });

        $this->post('/register', ['name' => 'Sam', 'email' => 'sam@example.com', 'password' => 'secure-password', 'password_confirmation' => 'secure-password'])
            ->assertSessionHasErrors(['email' => 'This email address is already registered.']);

        $this->assertDatabaseCount('users', 1);
        $this->assertGuest();
        DB::purge('racer');
    }

    public function test_unexpected_booking_database_failures_roll_back_without_becoming_slot_conflicts(): void
    {
        $connection = CalendarConnection::factory()->create();
        $this->failWritesTo('appointments');
        try {
            app(BookAppointment::class)->handle(User::find($connection->user_id), [
                'calendar_id' => $connection->calendar->external_id,
                'request_key' => (string) Str::uuid(), 'title' => 'Race', 'customer_name' => 'Sam', 'customer_email' => 'sam@example.com',
                'date' => now()->addDay()->toDateString(), 'start_time' => '12:00', 'timezone' => 'UTC', 'duration' => 30,
            ]);
            $this->fail('The database error was hidden.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode());
            $this->assertDatabaseCount('appointments', 0);
        } finally {
            DB::unprepared('DROP FUNCTION reject_test_write() CASCADE');
        }
    }

    public function test_unexpected_registration_database_failures_are_reported_without_authenticating(): void
    {
        $this->failWritesTo('users');
        Exceptions::fake();
        try {
            $this->post('/register', ['name' => 'Sam', 'email' => 'sam@example.com', 'password' => 'secure-password', 'password_confirmation' => 'secure-password'])
                ->assertServerError();
            Exceptions::assertReported(QueryException::class);
            $this->assertGuest();
            $this->assertDatabaseCount('users', 0);
        } finally {
            DB::unprepared('DROP FUNCTION reject_test_write() CASCADE');
        }
    }

    public function test_failed_database_updates_do_not_erase_local_appointments_when_linking_google(): void
    {
        $appointment = Appointment::factory()->local()->create();
        $connection = CalendarConnection::factory()->create(['user_id' => $appointment->user_id]);
        $this->failWritesTo('appointments', 'UPDATE');
        Exceptions::fake();
        try {
            $this->actingAs(User::find($appointment->user_id))->post('/appointments/'.$appointment->id.'/sync', ['calendar_id' => $connection->calendar->external_id])->assertServerError();
            Exceptions::assertReported(QueryException::class);
            $this->assertSame('local', $appointment->fresh()->sync_status);
            $this->assertNull($appointment->fresh()->calendar_connection_id);
        } finally {
            DB::unprepared('DROP FUNCTION reject_test_write() CASCADE');
        }
    }

    private function failWritesTo(string $table, string $operation = 'INSERT'): void
    {
        DB::unprepared("CREATE FUNCTION reject_test_write() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'Test write failure' USING ERRCODE = '23514'; END; $$ LANGUAGE plpgsql");
        DB::unprepared("CREATE TRIGGER reject_test_write BEFORE {$operation} ON {$table} FOR EACH ROW EXECUTE FUNCTION reject_test_write()");
    }
}
