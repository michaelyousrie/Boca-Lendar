<?php

namespace Tests\Feature\Actions;

use App\Actions\CancelAppointment;
use App\Models\Appointment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CancelAppointmentTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_cancellation_keeps_the_slot_reserved_until_external_deletion(): void
    {
        $appointment = Appointment::factory()->create(['sync_status' => 'synced', 'sync_attempts' => 3]);

        app(CancelAppointment::class)->handle($appointment);

        $this->assertDatabaseHas('appointments', ['id' => $appointment->id, 'status' => 'cancelled', 'holds_slot' => true, 'sync_status' => 'pending', 'sync_attempts' => 0]);
    }

    public function test_repeated_cancellation_does_not_restart_a_finished_sync(): void
    {
        $appointment = Appointment::factory()->create(['status' => 'cancelled', 'sync_status' => 'synced', 'holds_slot' => false]);

        app(CancelAppointment::class)->handle($appointment);

        $this->assertDatabaseHas('appointments', ['id' => $appointment->id, 'sync_status' => 'synced', 'holds_slot' => false]);
    }
}
