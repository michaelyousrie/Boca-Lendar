<?php

namespace App\Http\Controllers;

use App\Actions\BookAppointment;
use App\Actions\CancelAppointment;
use App\Actions\SyncExistingAppointment;
use App\Actions\UpdateAppointment;
use App\Http\Requests\StoreAppointmentRequest;
use App\Http\Requests\UpdateAppointmentRequest;
use App\Jobs\SyncAppointment;
use App\Models\Appointment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AppointmentController extends Controller
{
    public function store(StoreAppointmentRequest $request, BookAppointment $action): RedirectResponse
    {
        $appointment = $action->handle($request->user(), $request->validated());
        $this->dispatchPending($appointment);

        return redirect()->route('dashboard', ['date' => $request->date, 'timezone' => $request->timezone])
            ->with('success', 'Appointment saved.');
    }

    public function update(UpdateAppointmentRequest $request, string $appointment, UpdateAppointment $action): RedirectResponse
    {
        $owned = Appointment::where('user_id', $request->user()->id)->findOrFail($appointment);
        abort_unless($owned->writable(), 403, 'This calendar is read only.');
        $updated = $action->handle($owned, $request->validated());
        $this->dispatchPending($updated);

        return redirect()->route('dashboard', ['date' => $request->date, 'timezone' => $request->timezone])
            ->with('success', 'Appointment updated.');
    }

    public function cancel(Request $request, string $appointment, CancelAppointment $action): RedirectResponse
    {
        $owned = Appointment::where('user_id', $request->user()->id)->findOrFail($appointment);
        abort_unless($owned->writable(), 403, 'This calendar is read only.');
        $cancelled = $action->handle($owned);
        $this->dispatchPending($cancelled);

        return back()->with('success', 'Appointment cancelled.');
    }

    public function sync(Request $request, string $appointment, SyncExistingAppointment $action): RedirectResponse
    {
        $owned = Appointment::where('user_id', $request->user()->id)->findOrFail($appointment);
        $data = $request->validate(['calendar_id' => ['required', 'string', 'max:255']]);
        $synced = $action->handle($request->user(), $owned, $data['calendar_id']);
        $this->dispatchPending($synced);

        return back()->with('success', 'Google calendar selected.');
    }

    public function retry(Request $request, string $appointment): RedirectResponse
    {
        $owned = DB::transaction(function () use ($request, $appointment) {
            $owned = Appointment::where('user_id', $request->user()->id)->lockForUpdate()->findOrFail($appointment);
            if (in_array($owned->sync_status, ['local', 'synced'], true)) {
                return $owned;
            }
            $owned->update(['sync_status' => 'pending', 'sync_attempts' => 0, 'sync_error' => null, 'next_sync_at' => now()]);

            return $owned;
        });
        $this->dispatchPending($owned);

        return back()->with('success', $owned->sync_status === 'pending' ? 'Sync queued.' : 'Nothing to sync.');
    }

    private function dispatchPending(Appointment $appointment): void
    {
        if ($appointment->sync_status === 'pending') {
            SyncAppointment::dispatch($appointment->id);
        }
    }
}
