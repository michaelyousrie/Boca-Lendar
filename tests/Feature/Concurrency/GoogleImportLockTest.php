<?php

namespace Tests\Feature\Concurrency;

use App\Calendar\GoogleCalendar;
use App\Jobs\SyncGoogleCalendar;
use App\Models\GoogleCalendarSync;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class GoogleImportLockTest extends TestCase
{
    use DatabaseMigrations;

    public function test_an_import_waits_for_the_calendar_lock_even_when_google_has_no_events(): void
    {
        $sync = GoogleCalendarSync::factory()->create(['request_token' => (string) Str::uuid(), 'requested_at' => now()]);
        Http::fake(['*/events?*' => Http::response(['items' => [], 'nextSyncToken' => 'next'])]);
        config(['database.connections.racer' => config('database.connections.pgsql')]);
        $racer = DB::connection('racer');
        $racer->beginTransaction();

        try {
            $racer->table('booking_calendars')->where('external_id', $sync->calendar_id)->lockForUpdate()->first();
            DB::statement("SET lock_timeout TO '250ms'");

            try {
                (new SyncGoogleCalendar($sync->id, $sync->request_token))->handle(app(GoogleCalendar::class));
                $this->fail('The import advanced while another transaction held the calendar lock.');
            } catch (QueryException $exception) {
                $this->assertSame('55P03', $exception->getCode());
                $this->assertSame('saved-token', $sync->fresh()->sync_token);
            }
        } finally {
            DB::statement('SET lock_timeout TO DEFAULT');
            $racer->rollBack();
            DB::purge('racer');
        }
    }
}
