<?php

namespace Tests\Feature\Appointments;

use App\Actions\UpdateAppointment;
use App\Calendar\GoogleCalendar;
use App\Jobs\SyncAppointment;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UpdateAppointmentTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function data(Appointment $appointment, array $overrides = []): array
    {
        return ['revision' => $appointment->revision(), 'title' => 'Edited booking', 'customer_name' => 'Sam',
            'customer_email' => 'sam@example.com', 'date' => '2026-11-02', 'start_time' => '10:00',
            'timezone' => 'Africa/Cairo', 'duration' => 45, ...$overrides];
    }

    public function test_updates_local_booking_without_changing_its_identity_or_destination(): void
    {
        Queue::fake([SyncAppointment::class]);
        $appointment = Appointment::factory()->local()->create()->fresh();
        $this->actingAs(User::findOrFail($appointment->user_id))->post('/appointments/'.$appointment->id.'/update', $this->data($appointment, ['calendar_id' => 'untrusted', 'user_id' => 999]))
            ->assertRedirect(route('dashboard', ['date' => '2026-11-02', 'timezone' => 'Africa/Cairo']))->assertSessionHasNoErrors();
        $updated = $appointment->fresh();
        $this->assertSame('Edited booking', $updated->title);
        $this->assertSame('Sam', $updated->customer_name);
        $this->assertSame('2026-11-02 08:00:00', $updated->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-02 08:45:00', $updated->ends_at->format('Y-m-d H:i:s'));
        $this->assertSame($appointment->booking_calendar_id, $updated->booking_calendar_id);
        $this->assertSame('local', $updated->sync_status);
        $this->assertNotSame($appointment->revision(), $updated->revision());
        Queue::assertNothingPushed();
    }

    public function test_google_edit_resets_failed_delivery_and_updates_the_existing_event(): void
    {
        Queue::fake([SyncAppointment::class]);
        $appointment = Appointment::factory()->create(['sync_status' => 'failed', 'sync_attempts' => 5, 'sync_error' => 'Unavailable'])->fresh();
        $this->actingAs(User::findOrFail($appointment->user_id))->post('/appointments/'.$appointment->id.'/update', $this->data($appointment))->assertSessionHasNoErrors();
        $updated = $appointment->fresh();
        $this->assertSame('pending', $updated->sync_status);
        $this->assertSame(0, $updated->sync_attempts);
        $this->assertNull($updated->sync_error);
        Queue::assertPushed(SyncAppointment::class, fn ($job) => $job->appointmentId === $appointment->id);
        Http::fake(['*/events*' => Http::sequence()->push([], 409)
            ->push(['id' => $appointment->eventId(), 'extendedProperties' => ['private' => ['appointment_id' => $appointment->id]]])
            ->push(['id' => $appointment->eventId()])]);
        (new SyncAppointment($appointment->id))->handle(app(GoogleCalendar::class));
        $this->assertSame('synced', $appointment->fresh()->sync_status);
        Http::assertSent(fn ($request) => $request->method() === 'PATCH' && $request['summary'] === 'Edited booking' && $request['extendedProperties']['private']['customer_email'] === 'sam@example.com' && ! isset($request['description']) && ! isset($request['attendees']));
    }

    public function test_requires_authentication_ownership_and_valid_fields(): void
    {
        $appointment = Appointment::factory()->local()->create()->fresh();
        $url = '/appointments/'.$appointment->id.'/update';
        $this->post($url, $this->data($appointment))->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->post($url, $this->data($appointment))->assertNotFound();
        $this->actingAs(User::findOrFail($appointment->user_id))->post($url, [])->assertSessionHasErrors(['revision', 'title', 'customer_name', 'customer_email', 'date', 'start_time', 'duration', 'timezone']);
        $this->post($url, $this->data($appointment, ['title' => str_repeat('x', 151), 'duration' => 525601, 'customer_email' => 'invalid', 'timezone' => 'invalid']))->assertSessionHasErrors(['title', 'duration', 'customer_email', 'timezone']);
        $this->assertSame($appointment->title, $appointment->fresh()->title);
    }

    #[DataProvider('invalidTimes')]
    public function test_rejects_past_and_ambiguous_times_without_modifying_the_booking(array $changes, string $field): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 9, 24)->startOfDay());
        $appointment = Appointment::factory()->local()->create()->fresh();
        $this->actingAs(User::findOrFail($appointment->user_id))->post('/appointments/'.$appointment->id.'/update', $this->data($appointment, $changes))->assertSessionHasErrors($field);
        $this->assertSame($appointment->title, $appointment->fresh()->title);
    }

    public static function invalidTimes(): array
    {
        return [
            [['date' => '2026-09-24', 'start_time' => '00:00', 'timezone' => 'UTC'], 'start_time'],
            [['date' => '2027-09-25'], 'date'],
            [['date' => '2026-11-01', 'start_time' => '01:30', 'timezone' => 'America/New_York'], 'start_time'],
            [['date' => '2027-03-14', 'start_time' => '02:30', 'timezone' => 'America/New_York'], 'start_time'],
        ];
    }

    public function test_rejects_a_cancelled_booking_and_a_form_opened_before_another_edit(): void
    {
        $appointment = Appointment::factory()->local()->create()->fresh();
        $data = $this->data($appointment);
        $appointment->update(['title' => 'Changed elsewhere']);
        $this->actingAs(User::findOrFail($appointment->user_id))->post('/appointments/'.$appointment->id.'/update', $data)->assertSessionHasErrors('revision');
        $this->assertSame('Changed elsewhere', $appointment->fresh()->title);
        $appointment->update(['status' => 'cancelled', 'holds_slot' => false]);
        $this->post('/appointments/'.$appointment->id.'/update', $this->data($appointment))->assertSessionHasErrors('revision');
        $this->assertSame('cancelled', $appointment->fresh()->status);
    }

    public function test_overlap_rolls_back_the_edit_but_an_adjacent_slot_is_allowed(): void
    {
        $appointment = Appointment::factory()->local()->create()->fresh();
        Appointment::factory()->local()->create(['user_id' => $appointment->user_id, 'booking_calendar_id' => $appointment->booking_calendar_id, 'starts_at' => '2026-11-02 08:00:00', 'ends_at' => '2026-11-02 09:00:00']);
        $this->actingAs(User::findOrFail($appointment->user_id))->post('/appointments/'.$appointment->id.'/update', $this->data($appointment))->assertSessionHasErrors('start_time');
        $this->assertSame($appointment->starts_at->timestamp, $appointment->fresh()->starts_at->timestamp);
        $this->post('/appointments/'.$appointment->id.'/update', $this->data($appointment, ['start_time' => '11:00']))->assertSessionHasNoErrors();
    }

    public function test_background_delivery_does_not_invalidate_an_open_form_and_edits_can_overlap_their_old_slot(): void
    {
        Queue::fake([SyncAppointment::class]);
        $appointment = Appointment::factory()->create(['starts_at' => '2026-11-02 08:00:00', 'ends_at' => '2026-11-02 09:00:00'])->fresh();
        $data = $this->data($appointment, ['start_time' => '10:15']);
        $appointment->update(['sync_status' => 'synced', 'synced_at' => now()]);
        $this->actingAs(User::findOrFail($appointment->user_id))->post('/appointments/'.$appointment->id.'/update', $data)->assertSessionHasNoErrors();
        $this->assertSame('2026-11-02 08:15:00', $appointment->fresh()->starts_at->format('Y-m-d H:i:s'));
        Queue::assertPushed(SyncAppointment::class, fn ($job) => $job->appointmentId === $appointment->id);
    }

    public function test_unexpected_database_errors_are_not_disguised_as_conflicts(): void
    {
        $appointment = Appointment::factory()->local()->create()->fresh();
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT test_reject_edit CHECK (title <> 'Edited booking')");
        $this->expectException(QueryException::class);
        app(UpdateAppointment::class)->handle($appointment, $this->data($appointment));
    }
}
