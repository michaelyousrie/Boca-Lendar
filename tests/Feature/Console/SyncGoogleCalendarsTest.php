<?php

namespace Tests\Feature\Console;

use App\Jobs\SyncGoogleCalendar;
use App\Models\CalendarConnection;
use App\Models\GoogleCalendarSync;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncGoogleCalendarsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_scheduler_dispatches_the_same_job_as_manual_refresh_and_skips_revoked_accounts(): void
    {
        Queue::fake();
        CalendarConnection::factory()->count(2)->create();
        CalendarConnection::factory()->create(['needs_reconnect' => true]);
        $this->artisan('calendar:sync')->assertSuccessful();
        $this->artisan('calendar:sync')->assertSuccessful();
        Queue::assertPushed(SyncGoogleCalendar::class, 2);
        $this->assertDatabaseCount('google_calendar_syncs', 2);
        $this->assertNull(GoogleCalendarSync::first()->sync_token);
        Http::assertNothingSent();
        $events = app(Schedule::class)->events();
        $scheduled = collect($events)->first(fn ($event) => str_contains($event->command, 'calendar:sync'));
        $this->assertSame('* * * * *', $scheduled->expression);
        $this->assertTrue($scheduled->withoutOverlapping);
    }
}
