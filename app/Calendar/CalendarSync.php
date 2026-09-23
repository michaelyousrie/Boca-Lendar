<?php

namespace App\Calendar;

use App\Jobs\SyncGoogleCalendar;
use App\Models\CalendarConnection;
use App\Models\GoogleCalendarSync;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CalendarSync
{
    public function status(?CalendarConnection $connection): array
    {
        if (! $connection) {
            return [];
        }
        $syncs = GoogleCalendarSync::where('calendar_connection_id', $connection->id)->get()->keyBy('calendar_id');
        $result = [];
        foreach ($connection->calendars as $calendar) {
            $sync = $syncs->get($calendar['id']);
            $result[$calendar['id']] = [
                'refreshing' => $sync?->syncing() ?? false,
                'error' => $connection->needs_reconnect ? 'Reconnect Google to update appointments.'
                    : ($sync?->request_token && ! $sync->syncing() ? 'Google sync timed out. Try again.' : $sync?->sync_error),
            ];
        }

        return $result;
    }

    public function requestSync(CalendarConnection $connection, array $calendar): GoogleCalendarSync
    {
        return DB::transaction(function () use ($connection, $calendar) {
            $sync = GoogleCalendarSync::firstOrCreate([
                'calendar_connection_id' => $connection->id, 'calendar_id' => $calendar['id'],
            ], ['timezone' => $calendar['timezone']]);
            $sync = GoogleCalendarSync::whereKey($sync->id)->lockForUpdate()->firstOrFail();
            if ($connection->needs_reconnect || $sync->syncing()) {
                return $sync;
            }
            if ($sync->timezone !== $calendar['timezone']) {
                $sync->sync_token = null;
            }
            $sync->update(['timezone' => $calendar['timezone'], 'request_token' => (string) Str::uuid(), 'requested_at' => now(), 'sync_error' => null]);
            SyncGoogleCalendar::dispatch($sync->id, $sync->request_token)->afterCommit();

            return $sync;
        });
    }
}
