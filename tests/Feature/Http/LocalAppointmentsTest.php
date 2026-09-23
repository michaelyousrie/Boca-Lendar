<?php

namespace Tests\Feature\Http;

use App\Calendar\CalendarProvider;
use App\Jobs\SyncAppointment;
use App\Models\Appointment;
use App\Models\CalendarConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class LocalAppointmentsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function input(): array
    {
        return ['request_key' => (string) Str::uuid(), 'title' => 'Local consultation', 'customer_name' => 'Sam',
            'customer_email' => 'sam@example.com', 'date' => now()->addDay()->toDateString(),
            'start_time' => '14:00', 'timezone' => 'UTC', 'duration' => 30];
    }

    public function test_saves_without_google_and_never_queues_a_fake_sync(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create())->post('/appointments', $this->input())->assertSessionHasNoErrors();
        $appointment = Appointment::sole();
        $this->assertSame('local', $appointment->sync_status);
        $this->assertNull($appointment->calendar_connection_id);
        $this->assertNull($appointment->next_sync_at);
        $this->assertTrue($appointment->holds_slot);
        $this->assertSame('local', $appointment->calendar->provider);
        $this->artisan('appointments:sync')->assertSuccessful();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_local_calendar_conflicts_are_scoped_to_the_user(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create())->post('/appointments', $this->input())->assertSessionHasNoErrors();
        $this->post('/appointments', $this->input())->assertSessionHasErrors('start_time');
        $this->actingAs(User::factory()->create())->post('/appointments', $this->input())->assertSessionHasNoErrors();
        $this->assertDatabaseCount('appointments', 2);
    }

    public function test_local_cancellation_immediately_releases_the_slot_and_retry_does_nothing(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $input = $this->input();
        $this->actingAs($user)->post('/appointments', $input)->assertSessionHasNoErrors();
        $appointment = Appointment::sole();
        $this->post('/appointments/'.$appointment->id.'/cancel')->assertSessionHasNoErrors();
        $this->post('/appointments/'.$appointment->id.'/retry')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('appointments', ['id' => $appointment->id, 'status' => 'cancelled', 'sync_status' => 'local', 'holds_slot' => false]);
        $this->post('/appointments', array_replace($input, ['request_key' => (string) Str::uuid()]))->assertSessionHasNoErrors();
        Queue::assertNothingPushed();
    }

    public function test_a_google_connection_does_not_prevent_explicit_local_only_booking(): void
    {
        Queue::fake();
        $connection = CalendarConnection::factory()->create(['needs_reconnect' => true]);
        $this->actingAs(User::find($connection->user_id))->post('/appointments', $this->input() + ['calendar_id' => null])->assertSessionHasNoErrors();
        $this->assertSame('local', Appointment::sole()->sync_status);
        Queue::assertNothingPushed();
    }

    public function test_expired_google_access_does_not_lose_the_local_appointment(): void
    {
        Queue::fake();
        $connection = CalendarConnection::factory()->create(['needs_reconnect' => true]);
        $this->actingAs(User::find($connection->user_id))->post('/appointments', $this->input() + ['calendar_id' => $connection->calendar->external_id])->assertSessionHasNoErrors();
        $appointment = Appointment::sole();
        Queue::assertPushed(SyncAppointment::class);
        (new SyncAppointment($appointment->id))->handle(app(CalendarProvider::class));
        $this->assertSame('failed', $appointment->fresh()->sync_status);
        $this->assertTrue($appointment->fresh()->holds_slot);
        $this->assertStringContainsString('Reconnect', $appointment->fresh()->sync_error);
        Http::assertNothingSent();
    }
}
