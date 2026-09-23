<?php

namespace Tests\Feature\Http;

use App\Jobs\SyncGoogleCalendar;
use App\Models\CalendarConnection;
use App\Models\GoogleCalendarSync;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoogleEventsControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function payload(): array
    {
        return ['date' => '2026-11-01', 'timezone' => 'UTC'];
    }

    private function sync(): GoogleCalendarSync
    {
        $sync = GoogleCalendarSync::factory()->create();

        return $sync;
    }

    #[DataProvider('actions')]
    public function test_guests_unconnected_users_and_other_accounts_cannot_operate_on_events(string $action, string $key): void
    {
        Queue::fake();
        $other = $this->sync();
        $data = [...$this->payload(), $key => $action === 'refresh' ? [$other->calendar_id] : $other->calendar_id, 'event_id' => 'meeting'];
        $this->postJson('/calendar/events/'.$action, $data)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson('/calendar/events/'.$action, $data)->assertNotFound();
        $connection = CalendarConnection::factory()->create();
        $this->actingAs(User::findOrFail($connection->user_id))->postJson('/calendar/events/'.$action, $data)->assertNotFound();
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function actions(): array
    {
        return [['refresh', 'calendar_ids']];
    }

    public function test_refresh_validates_every_input_before_dispatching_jobs(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());
        $this->postJson('/calendar/events/refresh')->assertUnprocessable()->assertJsonValidationErrors(['calendar_ids']);
        $this->postJson('/calendar/events/refresh', ['calendar_ids' => 'not-an-array', 'date' => '2026-02-30', 'timezone' => 'invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors(['calendar_ids']);
        $this->postJson('/calendar/events/refresh', [...$this->payload(), 'calendar_ids' => [[], str_repeat('x', 1025), 'same', 'same']])
            ->assertUnprocessable()->assertJsonValidationErrors(['calendar_ids.0', 'calendar_ids.1', 'calendar_ids.2']);
        $this->postJson('/calendar/events/refresh', [...$this->payload(), 'calendar_ids' => range(1, 101)])->assertUnprocessable()->assertJsonValidationErrors('calendar_ids');
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_explicit_refresh_coalesces_pending_requests_and_accepts_read_only_calendars(): void
    {
        Queue::fake();
        $sync = $this->sync();
        $connection = $sync->connection;
        $connection->update(['calendars' => [[...$connection->calendars[0], 'writable' => false]]]);
        $this->actingAs(User::findOrFail($connection->user_id))->from('/appointments');
        $data = [...$this->payload(), 'calendar_ids' => [$sync->calendar_id]];
        $this->post('/calendar/events/refresh', $data)->assertRedirect('/appointments');
        $this->post('/calendar/events/refresh', $data)->assertRedirect('/appointments');
        Queue::assertPushed(SyncGoogleCalendar::class, 1);
        Http::assertNothingSent();
    }
}
