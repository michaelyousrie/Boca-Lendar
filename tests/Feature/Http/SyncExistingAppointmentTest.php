<?php

namespace Tests\Feature\Http;

use App\Jobs\SyncAppointment;
use App\Models\Appointment;
use App\Models\CalendarConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncExistingAppointmentTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function connect(Appointment $appointment): CalendarConnection
    {
        Queue::fake();
        $this->actingAs(User::find($appointment->user_id));

        return CalendarConnection::factory()->create(['user_id' => $appointment->user_id]);
    }

    public function test_an_existing_local_appointment_can_be_synced_once_without_changing_its_details(): void
    {
        $appointment = Appointment::factory()->local()->create();
        $connection = $this->connect($appointment);
        $input = ['calendar_id' => $connection->calendar->external_id];
        $this->post('/appointments/'.$appointment->id.'/sync', $input)->assertSessionHasNoErrors();
        $this->post('/appointments/'.$appointment->id.'/sync', $input)->assertSessionHasNoErrors();
        $fresh = $appointment->fresh();
        $this->assertSame('pending', $fresh->sync_status);
        $this->assertSame($appointment->starts_at->timestamp, $fresh->starts_at->timestamp);
        $this->assertSame($appointment->title, $fresh->title);
        $this->assertSame($connection->id, $fresh->calendar_connection_id);
        $this->assertDatabaseCount('appointments', 1);
        Queue::assertPushed(SyncAppointment::class);
    }

    public function test_sync_requires_a_connection_and_rejects_cancelled_appointments(): void
    {
        Queue::fake();
        $appointment = Appointment::factory()->local()->create();
        $this->actingAs(User::find($appointment->user_id))->post('/appointments/'.$appointment->id.'/sync', ['calendar_id' => 'some-calendar'])->assertSessionHasErrors('calendar_id');
        $connection = $this->connect($appointment);
        $appointment->update(['status' => 'cancelled', 'holds_slot' => false]);
        $this->post('/appointments/'.$appointment->id.'/sync', ['calendar_id' => $connection->calendar->external_id])->assertSessionHasErrors('calendar_id');
        $this->assertSame('local', $appointment->fresh()->sync_status);
        Queue::assertNothingPushed();
    }

    public function test_invalid_calendar_choices_leave_the_local_appointment_untouched(): void
    {
        $appointment = Appointment::factory()->local()->create();
        $this->connect($appointment);
        foreach ([null, [], str_repeat('a', 256), 'unavailable'] as $calendar) {
            $this->post('/appointments/'.$appointment->id.'/sync', ['calendar_id' => $calendar])->assertSessionHasErrors('calendar_id');
        }
        $this->assertSame('local', $appointment->fresh()->sync_status);
        Queue::assertNothingPushed();
    }

    public function test_a_destination_conflict_keeps_the_original_local_reservation(): void
    {
        $appointment = Appointment::factory()->local()->create();
        $connection = $this->connect($appointment);
        Appointment::factory()->create(['calendar_connection_id' => $connection->id, 'starts_at' => $appointment->starts_at, 'ends_at' => $appointment->ends_at]);
        $this->post('/appointments/'.$appointment->id.'/sync', ['calendar_id' => $connection->calendar->external_id])->assertSessionHasErrors('calendar_id');
        $this->assertSame('local', $appointment->fresh()->sync_status);
        $this->assertSame($appointment->booking_calendar_id, $appointment->fresh()->booking_calendar_id);
        $this->assertTrue($appointment->fresh()->holds_slot);
        Queue::assertNothingPushed();
    }

    public function test_a_linked_appointment_cannot_be_retargeted_to_another_calendar(): void
    {
        $appointment = Appointment::factory()->create(['sync_status' => 'synced']);
        $connection = $appointment->connection;
        $connection->update(['calendars' => [...$connection->calendars, ['id' => 'other', 'name' => 'Other', 'timezone' => 'UTC', 'writable' => true]]]);
        Queue::fake();
        $this->actingAs(User::find($appointment->user_id))->post('/appointments/'.$appointment->id.'/sync', ['calendar_id' => 'other'])->assertSessionHasErrors('calendar_id');
        $this->assertSame($appointment->booking_calendar_id, $appointment->fresh()->booking_calendar_id);
        Queue::assertNothingPushed();
    }
}
