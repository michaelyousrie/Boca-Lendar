<?php

namespace App\Http\Controllers;

use App\Calendar\CalendarSync;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GoogleEventsController extends Controller
{
    public function refresh(Request $request, CalendarSync $sync): RedirectResponse
    {
        $data = $request->validate([
            'calendar_ids' => ['required', 'array', 'max:100'],
            'calendar_ids.*' => ['required', 'string', 'max:1024', 'distinct'],
        ]);
        $connection = $request->user()->connection()->firstOrFail();
        abort_if(array_diff($data['calendar_ids'], array_column($connection->calendars, 'id')), 404);
        foreach ($data['calendar_ids'] as $calendarId) {
            $sync->requestSync($connection, collect($connection->calendars)->firstWhere('id', $calendarId));
        }

        return back();
    }
}
