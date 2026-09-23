<?php

namespace App\Console\Commands;

use App\Calendar\CalendarSync;
use App\Models\CalendarConnection;
use Illuminate\Console\Command;

class SyncGoogleCalendars extends Command
{
    protected $signature = 'calendar:sync';

    protected $description = 'Sync connected Google calendars using their last saved sync token';

    public function handle(CalendarSync $events): int
    {
        CalendarConnection::where('needs_reconnect', false)->chunkById(100, function ($connections) use ($events) {
            foreach ($connections as $connection) {
                foreach ($connection->calendars as $calendar) {
                    $events->requestSync($connection, $calendar);
                }
            }
        });

        return self::SUCCESS;
    }
}
