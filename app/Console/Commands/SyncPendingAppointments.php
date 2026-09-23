<?php

namespace App\Console\Commands;

use App\Jobs\SyncAppointment;
use App\Models\Appointment;
use Illuminate\Console\Command;

class SyncPendingAppointments extends Command
{
    protected $signature = 'appointments:sync {--inline : Process due syncs without a queue worker}';

    protected $description = 'Dispatch due calendar syncs from durable appointment records';

    public function handle(): int
    {
        Appointment::where('sync_status', 'pending')->where('next_sync_at', '<=', now())
            ->chunkById(100, function ($appointments) {
                foreach ($appointments as $appointment) {
                    $this->option('inline')
                        ? SyncAppointment::dispatchSync($appointment->id)
                        : SyncAppointment::dispatch($appointment->id);
                }
            });

        return self::SUCCESS;
    }
}
