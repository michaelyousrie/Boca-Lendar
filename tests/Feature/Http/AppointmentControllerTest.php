<?php

namespace Tests\Feature\Http;

use App\Jobs\SyncAppointment;
use App\Models\Appointment;
use App\Models\CalendarConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AppointmentControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function input(): array
    {
        return ['calendar_id' => 'demo-team', 'request_key' => (string) Str::uuid(), 'title' => 'Consultation', 'customer_name' => 'Alex', 'customer_email' => 'alex@example.com', 'date' => '2026-10-12', 'start_time' => '10:00', 'timezone' => 'Africa/Cairo', 'duration' => 45];
    }

    public function test_creates_a_booking_and_queues_sync_without_trusting_ownership_fields(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 23));
        $connection = CalendarConnection::factory()->create();
        Queue::fake();
        $this->actingAs(User::find($connection->user_id))->post('/appointments', array_replace($this->input(), ['calendar_id' => $connection->calendar->external_id]) + ['user_id' => 999, 'status' => 'cancelled', 'sync_status' => 'synced'])
            ->assertRedirect('/appointments?date=2026-10-12&timezone=Africa%2FCairo');
        $appointment = Appointment::sole();
        $this->assertSame($connection->user_id, $appointment->user_id);
        $this->assertSame('scheduled', $appointment->status);
        $this->assertSame('pending', $appointment->sync_status);
        Queue::assertPushed(SyncAppointment::class, fn ($job) => $job->appointmentId === $appointment->id);
    }

    #[DataProvider('invalidInputs')]
    public function test_rejects_invalid_booking_fields(string $field, mixed $value): void
    {
        $user = User::factory()->create();
        Queue::fake();
        $this->actingAs($user)->post('/appointments', array_replace($this->input(), [$field => $value]))->assertSessionHasErrors($field);
        $this->assertDatabaseCount('appointments', 0);
        Queue::assertNothingPushed();
    }

    public static function invalidInputs(): array
    {
        return [
            'long calendar' => ['calendar_id', str_repeat('a', 256)], 'array calendar' => ['calendar_id', []],
            'invalid key' => ['request_key', 'bad'], 'blank title' => ['title', '  '], 'long title' => ['title', str_repeat('a', 151)],
            'blank customer' => ['customer_name', ''], 'long customer' => ['customer_name', str_repeat('a', 101)],
            'bad email' => ['customer_email', 'wrong'], 'long email' => ['customer_email', str_repeat('a', 250).'@example.com'],
            'invalid day' => ['date', '2026-02-30'], 'invalid format' => ['date', '12/10/2026'],
            'bad hour' => ['start_time', '24:00'], 'seconds' => ['start_time', '10:00:01'],
            'bad zone' => ['timezone', 'GMT+3'], 'short duration' => ['duration', 4], 'long duration' => ['duration', 525601],
            'fractional duration' => ['duration', 30.5], 'array duration' => ['duration', []],
        ];
    }

    public function test_cancels_an_owned_booking_and_queues_its_deletion(): void
    {
        $appointment = Appointment::factory()->create(['sync_status' => 'synced']);
        Queue::fake();
        $this->actingAs(User::find($appointment->user_id))->from('/appointments')->post('/appointments/'.$appointment->id.'/cancel')
            ->assertRedirect('/appointments')->assertSessionHas('success', 'Appointment cancelled.');
        $this->assertSame('cancelled', $appointment->fresh()->status);
        Queue::assertPushed(SyncAppointment::class, fn ($job) => $job->appointmentId === $appointment->id);
    }

    #[DataProvider('mutationRoutes')]
    public function test_returns_404_for_another_users_booking(string $action): void
    {
        $appointment = Appointment::factory()->create();
        Queue::fake();
        $this->actingAs(User::factory()->create())->post('/appointments/'.$appointment->id.'/'.$action)->assertNotFound();
        $this->assertSame('scheduled', $appointment->fresh()->status);
        Queue::assertNothingPushed();
    }

    public static function mutationRoutes(): array
    {
        return [['cancel'], ['retry'], ['sync']];
    }

    public function test_retries_failed_sync_without_changing_the_cancelled_intent(): void
    {
        $appointment = Appointment::factory()->create(['status' => 'cancelled', 'sync_status' => 'failed', 'sync_error' => 'Failure', 'sync_attempts' => 5]);
        Queue::fake();
        $this->actingAs(User::find($appointment->user_id))->post('/appointments/'.$appointment->id.'/retry')->assertRedirect();
        $this->assertDatabaseHas('appointments', ['id' => $appointment->id, 'status' => 'cancelled', 'sync_status' => 'pending', 'sync_attempts' => 0, 'sync_error' => null]);
        Queue::assertPushed(SyncAppointment::class);
    }

    public function test_retry_does_not_reset_an_already_synced_appointment(): void
    {
        $appointment = Appointment::factory()->create(['sync_status' => 'synced']);
        Queue::fake();
        $this->actingAs(User::find($appointment->user_id))->post('/appointments/'.$appointment->id.'/retry')->assertRedirect();
        $this->assertSame('synced', $appointment->fresh()->sync_status);
    }

    public function test_guests_cannot_access_booking_or_calendar_actions(): void
    {
        $this->get('/appointments')->assertRedirect('/login');
        foreach (['/appointments', '/calendar/refresh', '/logout'] as $path) {
            $this->post($path)->assertRedirect('/login');
        }
        $this->get('/calendar/google')->assertRedirect('/login');
        $this->get('/calendar/google/callback')->assertRedirect('/login');
    }
}
