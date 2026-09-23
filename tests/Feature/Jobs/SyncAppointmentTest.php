<?php

namespace Tests\Feature\Jobs;

use App\Calendar\CalendarException;
use App\Calendar\CalendarProvider;
use App\Jobs\SyncAppointment;
use App\Models\Appointment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SyncAppointmentTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_repeated_sync_creates_one_event_and_marks_the_booking_synced(): void
    {
        $appointment = Appointment::factory()->create();
        $job = new SyncAppointment($appointment->id);
        $provider = $this->mock(CalendarProvider::class);
        $provider->shouldReceive('save')->once();
        $job->handle($provider);
        $job->handle($provider);

        $this->assertDatabaseHas('appointments', ['id' => $appointment->id, 'sync_status' => 'synced', 'holds_slot' => true]);
        $this->assertSame($appointment->id, $job->uniqueId());
    }

    public function test_cancelled_bookings_are_deleted_instead_of_recreated(): void
    {
        $appointment = Appointment::factory()->create(['status' => 'cancelled']);
        $provider = $this->mock(CalendarProvider::class);
        $provider->shouldReceive('delete')->once();

        (new SyncAppointment($appointment->id))->handle($provider);

        $this->assertDatabaseHas('appointments', ['id' => $appointment->id, 'sync_status' => 'synced', 'holds_slot' => false]);
    }

    public function test_cancellation_before_the_first_sync_is_safe(): void
    {
        $appointment = Appointment::factory()->create(['status' => 'cancelled']);
        Http::fakeSequence()->push([], 404)->push(['accessRole' => 'owner']);
        (new SyncAppointment($appointment->id))->handle(app(CalendarProvider::class));
        Http::assertSentCount(2);
        $this->assertFalse($appointment->fresh()->holds_slot);
    }

    #[DataProvider('failures')]
    public function test_preserves_reservations_and_schedules_only_retryable_failures(int $attempts, bool $retryable, string $expectedStatus): void
    {
        $this->freezeTime();
        $appointment = Appointment::factory()->create(['sync_attempts' => $attempts, 'status' => 'cancelled']);
        $provider = $this->mock(CalendarProvider::class);
        $provider->shouldReceive('delete')->once()->andThrow(new CalendarException('Safe error', $retryable));

        (new SyncAppointment($appointment->id))->handle($provider);

        $fresh = $appointment->fresh();
        $this->assertSame($expectedStatus, $fresh->sync_status);
        $this->assertTrue($fresh->holds_slot);
        $this->assertSame($attempts + 1, $fresh->sync_attempts);
        $this->assertSame('Safe error', $fresh->sync_error);
        if ($expectedStatus === 'pending') {
            $this->assertSame(now()->addSeconds(30 * 2 ** $attempts)->timestamp, $fresh->next_sync_at->timestamp);
        } else {
            $this->assertNull($fresh->next_sync_at);
        }
    }

    public static function failures(): array
    {
        return ['first retry' => [0, true, 'pending'], 'backoff' => [2, true, 'pending'], 'exhausted' => [4, true, 'failed'], 'permanent' => [0, false, 'failed']];
    }

    public function test_ignores_missing_and_not_yet_due_appointments(): void
    {
        $appointment = Appointment::factory()->create(['next_sync_at' => now()->addHour()]);
        $provider = $this->mock(CalendarProvider::class);
        $provider->shouldNotReceive('save');
        (new SyncAppointment($appointment->id))->handle($provider);
        (new SyncAppointment('00000000-0000-0000-0000-000000000000'))->handle($provider);
    }

    public function test_dispatcher_recovers_pending_work_without_an_http_request(): void
    {
        $due = Appointment::factory()->create();
        Appointment::factory()->create(['next_sync_at' => now()->addHour()]);
        Appointment::factory()->create(['sync_status' => 'failed']);
        Queue::fake();

        $this->artisan('appointments:sync')->assertSuccessful();

        Queue::assertPushed(SyncAppointment::class, fn ($job) => $job->appointmentId === $due->id);
        Queue::assertCount(1);
    }

    public function test_inline_dispatcher_processes_due_work(): void
    {
        $appointment = Appointment::factory()->create();
        Http::fake(['*/events*' => Http::response(['id' => $appointment->eventId()])]);
        $this->artisan('appointments:sync --inline')->assertSuccessful();
        $this->assertSame('synced', $appointment->fresh()->sync_status);
    }
}
