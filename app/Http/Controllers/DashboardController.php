<?php

namespace App\Http\Controllers;

use App\Calendar\CalendarSync;
use App\Calendar\UpcomingEvents;
use App\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, CalendarSync $sync, UpcomingEvents $upcomingEvents): Response
    {
        $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'month' => ['sometimes', 'date_format:Y-m'],
            'timezone' => ['sometimes', Rule::in(timezone_identifiers_list())],
        ]);
        $connection = $request->user()->connection()->with('calendar')->first();
        $timezone = $request->input('timezone', $connection?->calendar?->timezone ?? 'Africa/Cairo');
        $date = $request->input('date', now($timezone)->toDateString());
        $month = $request->input('month', substr($date, 0, 7));
        $start = CarbonImmutable::parse($date, $timezone)->startOfDay();
        $end = $start->addDay()->startOfDay();
        $owned = Appointment::with(['calendar', 'connection'])->where('user_id', $request->user()->id);
        $appointments = (clone $owned)->overlapping($start, $end)->orderBy('starts_at')->get();
        $syncIssues = (clone $owned)->whereIn('sync_status', ['pending', 'failed'])->orderBy('sync_status')->orderBy('starts_at')->get();

        return Inertia::render('Dashboard', [
            'date' => $date, 'timezone' => $timezone, 'timezones' => timezone_identifiers_list(),
            'today' => now($timezone)->toDateString(),
            'month' => $month,
            'appointmentDates' => fn () => $this->appointmentDates($request->user()->id, $month, $timezone),
            'calendarSync' => fn () => $sync->status($connection),
            'upcomingEvents' => fn () => $upcomingEvents->forUser($request->user()->id, $timezone),
            'googleConfigured' => (bool) (config('services.google.client_id') && config('services.google.client_secret')),
            'connection' => $connection ? [
                'email' => $connection->email,
                'calendars' => $connection->calendars, 'needs_reconnect' => $connection->needs_reconnect,
                'selected_calendar_id' => $connection->calendar?->external_id,
            ] : null,
            'appointments' => $appointments->map(fn (Appointment $appointment) => [
                'id' => $appointment->id, 'title' => $appointment->title, 'revision' => $appointment->revision(),
                'customer_name' => $appointment->customer_name, 'customer_email' => $appointment->customer_email,
                'starts_at' => $appointment->all_day ? $appointment->displayStart($timezone)->toDateString() : $appointment->starts_at->toIso8601String(),
                'ends_at' => $appointment->all_day ? $appointment->displayEnd($timezone)->toDateString() : $appointment->ends_at->toIso8601String(),
                'all_day' => $appointment->all_day, 'writable' => $appointment->writable(), 'url' => $appointment->url,
                'calendar_id' => $appointment->calendar_connection_id ? $appointment->calendar->external_id : null,
                'timezone' => $appointment->timezone, 'status' => $appointment->status,
                'sync_status' => $appointment->sync_status, 'sync_error' => $appointment->sync_error,
                'calendar_name' => $appointment->calendar->name, 'holds_slot' => $appointment->holds_slot,
            ]),
            'syncIssues' => $syncIssues->map(fn (Appointment $appointment) => [
                'id' => $appointment->id, 'title' => $appointment->title,
                'date' => $appointment->displayStart($timezone)->toDateString(),
                'starts_at' => $appointment->starts_at->toIso8601String(),
                'status' => $appointment->status, 'all_day' => $appointment->all_day, 'sync_status' => $appointment->sync_status,
                'sync_error' => $appointment->sync_error,
            ]),
        ]);
    }

    private function appointmentDates(int $userId, string $month, string $timezone): array
    {
        $start = CarbonImmutable::parse($month.'-01', $timezone)->startOfDay();
        $end = $start->addMonth()->startOfDay();
        $appointments = Appointment::where('user_id', $userId)->where('status', 'scheduled')
            ->overlapping($start, $end)->get();
        $dates = [];

        foreach ($appointments as $appointment) {
            $day = $appointment->displayStart($timezone)->max($start)->startOfDay();
            $until = $appointment->displayEnd($timezone)->min($end);
            while ($day->lessThan($until)) {
                $dates[$day->toDateString()] = true;
                $day = $day->addDay()->startOfDay();
            }
        }

        $dates = array_keys($dates);
        sort($dates);

        return $dates;
    }
}
