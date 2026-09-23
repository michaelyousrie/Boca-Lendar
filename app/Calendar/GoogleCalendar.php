<?php

namespace App\Calendar;

use App\Models\Appointment;
use App\Models\CalendarConnection;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class GoogleCalendar implements CalendarProvider
{
    private const API = 'https://www.googleapis.com/calendar/v3';

    public function calendars(CalendarConnection $connection): array
    {
        $calendars = [];
        $page = null;
        $seen = [];

        do {
            $response = $this->request($connection, 'GET', '/users/me/calendarList', array_filter(['maxResults' => 250, 'pageToken' => $page]));
            $this->ensureSuccess($response);
            $items = $response->json('items');
            if (! is_array($items)) {
                throw new CalendarException('Google returned an unreadable calendar list. Try again.');
            }
            foreach ($items as $item) {
                if (! isset($item['id'])) {
                    throw new CalendarException('Google returned an unreadable calendar list. Try again.');
                }
                $calendars[] = [
                    'id' => $item['id'],
                    'name' => $item['summaryOverride'] ?? $item['summary'] ?? $item['id'],
                    'timezone' => in_array($item['timeZone'] ?? '', timezone_identifiers_list(), true) ? $item['timeZone'] : 'UTC',
                    'writable' => in_array($item['accessRole'] ?? '', ['owner', 'writer'], true),
                ];
            }
            $page = $response->json('nextPageToken');
            if ($page && (in_array($page, $seen, true) || count($seen) >= 50)) {
                throw new CalendarException('Google could not finish listing calendars. Try again.');
            }
            $seen[] = $page;
        } while ($page);

        return $calendars;
    }

    public function changes(CalendarConnection $connection, string $calendarId, ?string $syncToken, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $path = '/calendars/'.rawurlencode($calendarId).'/events';
        $query = ['maxResults' => 2500, 'singleEvents' => 'false', 'showDeleted' => 'true', 'timeZone' => $start->timezoneName];
        $query += $syncToken !== null ? ['syncToken' => $syncToken] : ['timeMin' => $start->toRfc3339String(), 'timeMax' => $end->toRfc3339String()];
        $page = null;
        $seen = [];
        $events = [];
        $deleted = [];
        $series = [];
        do {
            $response = $this->request($connection, 'GET', $path, $query + ($page !== null ? ['pageToken' => $page] : []));
            if ($response->status() === 410 && $syncToken !== null) {
                return $this->changes($connection, $calendarId, null, $start, $end);
            }
            $this->ensureSuccess($response);
            foreach ($this->items($response) as $item) {
                if (($item['status'] ?? null) === 'cancelled') {
                    $deleted[$item['id']] = true;
                } elseif (! empty($item['recurrence'])) {
                    $series[$item['id']] = true;
                } else {
                    $events[$item['id']] = GoogleEvent::from($item) + ['recurring_event_id' => $item['recurringEventId'] ?? null];
                }
            }
            $page = $this->nextPage($response, $seen);
        } while ($page !== null);
        $nextToken = $response->json('nextSyncToken');
        if (! is_string($nextToken) || $nextToken === '') {
            throw new CalendarException('Google did not return a sync token. Try again.');
        }
        foreach (array_map('strval', array_keys($series)) as $id) {
            if (isset($deleted[$id])) {
                continue;
            }
            $events = array_filter($events, fn ($event) => $event['recurring_event_id'] !== $id);
            $page = null;
            $seen = [];
            do {
                $response = $this->request($connection, 'GET', $path.'/'.rawurlencode($id).'/instances', [
                    'maxResults' => 2500, 'showDeleted' => 'false', 'timeZone' => $start->timezoneName,
                    'timeMin' => $start->toRfc3339String(), 'timeMax' => $end->toRfc3339String(),
                    ...($page !== null ? ['pageToken' => $page] : []),
                ]);
                $this->ensureSuccess($response);
                foreach ($this->items($response) as $item) {
                    if (($item['status'] ?? null) !== 'cancelled') {
                        $events[$item['id']] = GoogleEvent::from($item) + ['recurring_event_id' => $id];
                    }
                }
                $page = $this->nextPage($response, $seen);
            } while ($page !== null);
        }

        return [
            'events' => array_values($events), 'deleted' => array_map('strval', array_keys($deleted)), 'series' => array_map('strval', array_keys($series)),
            'sync_token' => $nextToken, 'full' => $syncToken === null,
        ];
    }

    private function items(Response $response): array
    {
        $items = $response->json('items');
        if (! is_array($items) || ! array_is_list($items)) {
            throw new CalendarException('Google returned an unreadable event list. Try again.');
        }
        foreach ($items as $item) {
            if (! is_array($item) || ! is_string($item['id'] ?? null) || $item['id'] === '') {
                throw new CalendarException('Google returned an unreadable event. Try again.');
            }
        }

        return $items;
    }

    private function nextPage(Response $response, array &$seen): ?string
    {
        $page = $response->json('nextPageToken');
        if ($page !== null && (! is_string($page) || $page === '' || isset($seen[$page]))) {
            throw new CalendarException('Google could not finish listing events. Try again.');
        }
        if ($page !== null) {
            $seen[$page] = true;
        }

        return $page;
    }

    public function save(Appointment $appointment): void
    {
        $path = $this->eventPath($appointment);
        $payload = EventPayload::for($appointment);
        $response = $appointment->google_event_id ? null : $this->request($appointment->connection, 'POST', $path.'?sendUpdates=none', $payload);
        if ($response === null || $response->status() === 409) {
            $existing = $this->request($appointment->connection, 'GET', $path.'/'.rawurlencode($appointment->eventId()));
            $this->ensureSuccess($existing);
            if (! $appointment->google_event_id) {
                $this->ensureOwned($existing, $appointment);
            }
            if ($existing->json('status') === 'cancelled') {
                throw new CalendarException('This appointment was removed in Google. Refresh appointments before making further changes.', false);
            }
            unset($payload['description']);
            foreach (['start', 'end'] as $field) {
                $payload[$field] += $appointment->all_day ? ['dateTime' => null, 'timeZone' => null] : ['date' => null];
            }
            $headers = $existing->json('etag') ? ['If-Match' => $existing->json('etag')] : [];
            $response = $this->request($appointment->connection, 'PATCH', $path.'/'.rawurlencode($appointment->eventId()).'?sendUpdates=none', $payload, $headers);
            if ($response->status() === 412) {
                throw new CalendarException('This appointment changed during sync. Your saved edit will be retried.');
            }
        }
        $this->ensureSuccess($response);
        if ($response->json('id') !== $appointment->eventId()) {
            throw new CalendarException('Google did not confirm the appointment. Sync will be retried.');
        }
    }

    public function delete(Appointment $appointment): void
    {
        $path = $this->eventPath($appointment).'/'.rawurlencode($appointment->eventId());
        $event = $this->request($appointment->connection, 'GET', $path);
        if (in_array($event->status(), [404, 410], true)) {
            $this->ensureCalendarAccess($appointment->connection, $appointment->calendar->external_id);

            return;
        }
        $this->ensureSuccess($event);
        if ($event->json('status') === 'cancelled') {
            $this->ensureCalendarAccess($appointment->connection, $appointment->calendar->external_id);

            return;
        }
        if (! $appointment->google_event_id) {
            $this->ensureOwned($event, $appointment);
        }
        $response = $this->request($appointment->connection, 'DELETE', $path.'?sendUpdates=none');
        if (in_array($response->status(), [404, 410], true)) {
            $this->ensureCalendarAccess($appointment->connection, $appointment->calendar->external_id);

            return;
        }
        $this->ensureSuccess($response);
    }

    public function event(Appointment $appointment): ?array
    {
        $event = $this->request($appointment->connection, 'GET', $this->eventPath($appointment).'/'.rawurlencode($appointment->eventId()));
        if (in_array($event->status(), [404, 410], true)) {
            $this->ensureCalendarAccess($appointment->connection, $appointment->calendar->external_id, false);

            return null;
        }
        $this->ensureSuccess($event);
        if ($event->json('id') !== $appointment->eventId() || ! in_array($event->json('status'), ['confirmed', 'tentative', 'cancelled'], true)) {
            throw new CalendarException('Google returned an unreadable event. Try refreshing appointments.');
        }
        if ($event->json('status') === 'cancelled') {
            $this->ensureCalendarAccess($appointment->connection, $appointment->calendar->external_id, false);

            return null;
        }
        if (! $appointment->google_event_id) {
            $this->ensureOwned($event, $appointment);
        }

        return GoogleEvent::from($event->json()) + ['recurring_event_id' => $event->json('recurringEventId')];
    }

    private function ensureCalendarAccess(CalendarConnection $connection, string $calendarId, bool $writable = true): void
    {
        // Google also uses 404 for inaccessible calendars, which is not proof of deletion.
        $response = $this->request($connection, 'GET', '/users/me/calendarList/'.rawurlencode($calendarId));
        $this->ensureSuccess($response);
        if (! in_array($response->json('accessRole'), ($writable ? ['owner', 'writer'] : ['owner', 'writer', 'reader']), true)) {
            throw new CalendarException('Write access to this calendar was removed. Restore access, then retry sync.', false);
        }
    }

    private function ensureOwned(Response $response, Appointment $appointment): void
    {
        if ($response->json('extendedProperties.private.appointment_id') !== $appointment->id) {
            throw new CalendarException('The event identifier belongs to another event. Calendar sync needs review.', false);
        }
    }

    private function eventPath(Appointment $appointment): string
    {
        return '/calendars/'.rawurlencode($appointment->calendar->external_id).'/events';
    }

    private function request(CalendarConnection $connection, string $method, string $path, array $data = [], array $headers = []): Response
    {
        $token = $this->accessToken($connection);
        $options = match ($method) {
            'GET' => ['query' => $data],
            'DELETE' => [],
            default => ['json' => $data],
        };
        try {
            $response = Http::withToken($token)->withHeaders($headers)->acceptJson()->connectTimeout(3)->timeout(10)
                ->send($method, self::API.$path, $options);
        } catch (ConnectionException) {
            throw new CalendarException('Google Calendar is taking too long to respond. Sync will be retried.');
        }
        if ($response->status() === 401) {
            $connection->update(['needs_reconnect' => true]);
            throw new CalendarException('Google access has expired. Reconnect the same account, then retry sync.', false);
        }

        return $response;
    }

    private function accessToken(CalendarConnection $connection): string
    {
        $result = DB::transaction(function () use ($connection) {
            $locked = CalendarConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            if ($locked->needs_reconnect) {
                throw new CalendarException('Reconnect your Google account, then retry sync.', false);
            }
            if ($locked->access_token && $locked->expires_at?->greaterThan(now()->addMinute())) {
                return $locked->access_token;
            }
            if (! $locked->refresh_token) {
                $locked->update(['needs_reconnect' => true]);

                return new CalendarException('Reconnect your Google account to allow calendar sync.', false);
            }
            try {
                $response = Http::asForm()->connectTimeout(3)->timeout(10)->post('https://oauth2.googleapis.com/token', [
                    'client_id' => config('services.google.client_id'),
                    'client_secret' => config('services.google.client_secret'),
                    'refresh_token' => $locked->refresh_token,
                    'grant_type' => 'refresh_token',
                ]);
            } catch (ConnectionException) {
                throw new CalendarException('Google authentication is taking too long. Sync will be retried.');
            }
            if ($response->status() === 400 || $response->status() === 401) {
                $locked->update(['needs_reconnect' => true]);

                return new CalendarException('Google access was revoked. Reconnect the same account, then retry sync.', false);
            }
            $this->ensureSuccess($response);
            if (! is_string($response->json('access_token')) || ! is_numeric($response->json('expires_in'))) {
                throw new CalendarException('Google returned an unreadable authentication response. Try again.');
            }
            $locked->update([
                'access_token' => $response->json('access_token'),
                'refresh_token' => $response->json('refresh_token') ?: $locked->refresh_token,
                'expires_at' => now()->addSeconds((int) $response->json('expires_in')),
            ]);

            return $locked->access_token;
        });

        if ($result instanceof CalendarException) {
            throw $result;
        }

        return $result;
    }

    private function ensureSuccess(Response $response): void
    {
        if ($response->successful()) {
            return;
        }
        $reason = $response->json('error.errors.0.reason');
        if ($response->serverError() || $response->status() === 429 || in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded'], true)) {
            throw new CalendarException('Google Calendar is temporarily unavailable or busy. Sync will be retried.');
        }
        throw new CalendarException('Google could not access this calendar or event. Check calendar permissions, then retry sync.', false);
    }
}
