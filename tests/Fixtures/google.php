<?php

use App\Calendar\CalendarProvider;
use App\Calendar\CalendarSync;
use App\Calendar\EventPayload;
use App\Calendar\GoogleEvent;
use App\Jobs\SyncAppointment;
use App\Jobs\SyncGoogleCalendar;
use App\Models\Appointment;
use App\Models\BookingCalendar;
use App\Models\CalendarConnection;
use App\Models\GoogleCalendarSync;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('testing') || config('database.connections.pgsql.database') !== 'boca_e2e') {
    throw new RuntimeException('This fixture only runs against the browser test database.');
}

Http::preventStrayRequests();
$mode = $argv[1];
if ($mode === 'connect') {
    $user = User::where('email', $argv[2])->firstOrFail();
    $connection = CalendarConnection::factory()->create(['user_id' => $user->id, 'selected_calendar_id' => null, 'calendars' => [
        ['id' => 'work-'.$user->id, 'name' => 'Work calendar', 'timezone' => 'UTC', 'writable' => true],
        ['id' => 'readonly', 'name' => 'Read-only calendar', 'timezone' => 'UTC', 'writable' => false],
    ]]);
    foreach ($connection->calendars as $calendar) {
        GoogleCalendarSync::factory()->create(['calendar_connection_id' => $connection->id, 'calendar_id' => $calendar['id']]);
    }
} elseif (str_starts_with($mode, 'events-')) {
    $connection = User::where('email', $argv[2])->firstOrFail()->connection;
    $meeting = ['id' => 'meeting', 'summary' => 'Team review', 'start' => ['dateTime' => '2026-11-01T10:00:00Z'], 'end' => ['dateTime' => '2026-11-01T11:00:00Z'], 'htmlLink' => 'https://www.google.com/calendar/event?eid=test'];
    $holiday = ['id' => 'holiday', 'summary' => 'Team day off', 'start' => ['date' => '2026-11-01'], 'end' => ['date' => '2026-11-02']];
    if ($mode === 'events-seed-all-day') {
        $meeting = [...$holiday, 'id' => 'meeting', 'summary' => 'Team day off'];
    }
    if (in_array($mode, ['events-seed', 'events-seed-all-day'])) {
        foreach ($connection->calendars as $calendar) {
            $sync = GoogleCalendarSync::where('calendar_connection_id', $connection->id)->where('calendar_id', $calendar['id'])->sole();
            $event = GoogleEvent::from($calendar['writable'] ? $meeting : $holiday);
            $bookingCalendar = BookingCalendar::firstOrCreate(['provider' => 'google', 'external_id' => $calendar['id']], ['name' => $calendar['name'], 'timezone' => $calendar['timezone']]);
            Appointment::factory()->imported()->create(['calendar_connection_id' => $connection->id, 'booking_calendar_id' => $bookingCalendar->id,
                'google_event_id' => $event['id'], 'title' => $event['title'], 'all_day' => $event['all_day'], 'starts_at' => $event['starts_at'], 'ends_at' => $event['ends_at'], 'url' => $event['url']]);
            app(CalendarSync::class)->requestSync($connection, $calendar);
        }
    } else {
        Http::fake(function (Request $request) use ($mode, $meeting, $holiday, $connection) {
            if ($mode === 'events-delete-bookings') {
                if (str_contains($request->url(), '/users/me/calendarList/')) {
                    return Http::response(['accessRole' => 'owner']);
                }
                if (str_contains($request->url(), '/events/')) {
                    return Http::response([], 404);
                }

                return Http::response(['items' => Appointment::where('calendar_connection_id', $connection->id)->where('status', 'scheduled')->get()->map(fn ($appointment) => ['id' => $appointment->eventId(), 'status' => 'cancelled'])->all(), 'nextSyncToken' => 'after-deletion']);
            }
            if ($mode === 'events-preserve-bookings') {
                $appointments = Appointment::with('calendar')->where('calendar_connection_id', $connection->id)->where('status', 'scheduled')->get();

                return Http::response(['items' => $appointments->filter(fn ($appointment) => str_contains($request->url(), '/calendars/'.rawurlencode($appointment->calendar->external_id).'/events?'))->map(fn ($appointment) => EventPayload::for($appointment))->values()->all(), 'nextSyncToken' => 'preserved']);
            }
            $work = str_contains($request->url(), '/calendars/work-');
            if ($mode === 'events-fail' && $work) {
                return Http::response([], 503);
            }
            $meeting['summary'] = 'Updated team review';

            return Http::response(['items' => $mode === 'events-empty' ? [['id' => $work ? 'meeting' : 'holiday', 'status' => 'cancelled']] : [$work ? $meeting : $holiday], 'nextSyncToken' => 'next-token']);
        });
        foreach (GoogleCalendarSync::where('calendar_connection_id', $connection->id)->whereNotNull('request_token')->get() as $sync) {
            (new SyncGoogleCalendar($sync->id, $sync->request_token))->handle(app(CalendarProvider::class));
        }
    }
} elseif ($mode === 'schedule') {
    Artisan::call('calendar:sync');
} elseif ($mode === 'fail') {
    $user = User::where('email', $argv[2])->firstOrFail();
    $appointment = Appointment::where('user_id', $user->id)->where('sync_status', 'pending')->sole();
    Http::fake(['*' => Http::response([], 403)]);
    (new SyncAppointment($appointment->id))->handle(app(CalendarProvider::class));
} elseif ($mode === 'sync') {
    Http::fake(function (Request $request) {
        if ($request->method() === 'POST') {
            return Http::response(['id' => $request['id']]);
        }
        if ($request->method() === 'DELETE') {
            return Http::response([], 204);
        }
        $id = basename(parse_url($request->url(), PHP_URL_PATH));
        $appointment = Appointment::where('google_event_id', $id)->orWhereRaw("replace(id::text, '-', '') = ?", [$id])->firstOrFail();

        return Http::response(EventPayload::for($appointment) + ['status' => 'confirmed', 'etag' => '"browser-version"']);
    });
    Artisan::call('appointments:sync', ['--inline' => true]);
} else {
    throw new RuntimeException('Unknown browser fixture action.');
}
