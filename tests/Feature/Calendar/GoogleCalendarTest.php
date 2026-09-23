<?php

namespace Tests\Feature\Calendar;

use App\Calendar\CalendarException;
use App\Calendar\EventPayload;
use App\Calendar\GoogleCalendar;
use App\Models\Appointment;
use App\Models\CalendarConnection;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoogleCalendarTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const API = 'https://www.googleapis.com/calendar/v3';

    private function appointment(): Appointment
    {
        return Appointment::factory()->create(['calendar_connection_id' => CalendarConnection::factory()]);
    }

    private function path(Appointment $appointment): string
    {
        return self::API.'/calendars/'.rawurlencode($appointment->calendar->external_id).'/events';
    }

    #[DataProvider('deletedEvents')]
    public function test_confirms_external_deletion_without_writing_to_google(int $status): void
    {
        $appointment = $this->appointment();
        Http::fake([
            $this->path($appointment).'/'.$appointment->eventId() => Http::response(['id' => $appointment->eventId(), 'status' => 'cancelled'], $status),
            self::API.'/users/me/calendarList/*' => Http::response(['accessRole' => 'owner']),
        ]);

        $this->assertNull(app(GoogleCalendar::class)->event($appointment));
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public static function deletedEvents(): array
    {
        return [[200], [404], [410]];
    }

    #[DataProvider('unconfirmedDeletions')]
    public function test_uncertain_google_responses_never_confirm_deletion(int $status, array $body, int $accessStatus, string $role): void
    {
        $appointment = $this->appointment();
        Http::fake([
            $this->path($appointment).'/'.$appointment->eventId() => Http::response($body, $status),
            self::API.'/users/me/calendarList/*' => Http::response(['accessRole' => $role], $accessStatus),
        ]);
        $this->expectException(CalendarException::class);
        app(GoogleCalendar::class)->event($appointment);
    }

    public static function unconfirmedDeletions(): array
    {
        return [
            'calendar hidden' => [404, [], 404, ''],
            'access denied' => [403, [], 200, 'owner'],
            'Google unavailable' => [503, [], 200, 'owner'],
            'unreadable event' => [200, [], 200, 'owner'],
            'wrong event' => [200, ['id' => 'someone-else', 'status' => 'cancelled'], 200, 'owner'],
        ];
    }

    public function test_an_active_event_outside_the_current_month_is_not_deleted(): void
    {
        $appointment = $this->appointment();
        $event = EventPayload::for($appointment) + ['status' => 'confirmed'];
        $event['start'] = ['dateTime' => '2027-01-01T10:00:00Z'];
        $event['end'] = ['dateTime' => '2027-01-01T11:00:00Z'];
        Http::fake([$this->path($appointment).'/'.$appointment->eventId() => Http::response($event)]);
        $this->assertSame('2027-01-01T10:00:00+00:00', app(GoogleCalendar::class)->event($appointment)['starts_at']);
        Http::assertSentCount(1);
    }

    public function test_a_response_without_a_known_status_cannot_confirm_deletion(): void
    {
        $appointment = $this->appointment();
        Http::fake([$this->path($appointment).'*' => Http::response(EventPayload::for($appointment))]);
        $this->expectException(CalendarException::class);
        app(GoogleCalendar::class)->event($appointment);
    }

    public function test_lists_all_pages_and_marks_read_only_calendars(): void
    {
        $connection = CalendarConnection::factory()->create();
        Http::fake([self::API.'/users/me/calendarList*' => Http::sequence()
            ->push(['items' => [['id' => 'shared@example.com', 'summary' => 'Shared', 'accessRole' => 'writer', 'timeZone' => 'Africa/Cairo']], 'nextPageToken' => 'page2'])
            ->push(['items' => [['id' => 'holidays', 'summaryOverride' => 'Holidays', 'accessRole' => 'reader', 'timeZone' => 'invalid']]])]);

        $calendars = app(GoogleCalendar::class)->calendars($connection);

        $this->assertSame([
            ['id' => 'shared@example.com', 'name' => 'Shared', 'timezone' => 'Africa/Cairo', 'writable' => true],
            ['id' => 'holidays', 'name' => 'Holidays', 'timezone' => 'UTC', 'writable' => false],
        ], $calendars);
        Http::assertSent(fn ($request) => ($request['pageToken'] ?? null) === 'page2' && $request->hasHeader('Authorization', 'Bearer test-access'));
    }

    #[DataProvider('badLists')]
    public function test_rejects_malformed_or_looping_calendar_lists(array $body): void
    {
        $connection = CalendarConnection::factory()->create();
        Http::fake([self::API.'/users/me/calendarList*' => Http::response($body)]);
        $this->expectException(CalendarException::class);
        app(GoogleCalendar::class)->calendars($connection);
    }

    public static function badLists(): array
    {
        return ['missing items' => [[]], 'missing id' => [['items' => [['summary' => 'Bad']]]], 'loop' => [['items' => [], 'nextPageToken' => 'same']]];
    }

    public function test_creates_a_stable_event_without_sending_customer_invitations(): void
    {
        $appointment = $this->appointment();
        Http::fake([$this->path($appointment).'*' => Http::response(['id' => $appointment->eventId()])]);

        app(GoogleCalendar::class)->save($appointment);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request['id'] === $appointment->eventId()
            && $request['start']['timeZone'] === 'UTC'
            && $request['extendedProperties']['private']['appointment_id'] === $appointment->id
            && ! isset($request['attendees']) && str_contains($request->url(), 'sendUpdates=none'));
    }

    public function test_recovers_an_insert_that_succeeded_before_a_lost_response(): void
    {
        $appointment = $this->appointment();
        Http::fake([$this->path($appointment).'*' => Http::sequence()->push([], 409)
            ->push(EventPayload::for($appointment) + ['status' => 'confirmed'])
            ->push(['id' => $appointment->eventId()])]);

        app(GoogleCalendar::class)->save($appointment);

        Http::assertSentInOrder([
            fn ($request) => $request->method() === 'POST',
            fn ($request) => $request->method() === 'GET',
            fn ($request) => $request->method() === 'PATCH' && $request['id'] === $appointment->eventId(),
        ]);
    }

    #[DataProvider('unsafeExistingEvents')]
    public function test_does_not_overwrite_an_unrelated_or_externally_deleted_event(array $event): void
    {
        $appointment = $this->appointment();
        $payload = EventPayload::for($appointment);
        Http::fake([$this->path($appointment).'*' => Http::sequence()->push([], 409)->push(array_replace_recursive($payload, $event))]);

        try {
            app(GoogleCalendar::class)->save($appointment);
            $this->fail('An unsafe event was overwritten.');
        } catch (CalendarException $exception) {
            $this->assertFalse($exception->retryable);
        }
        Http::assertSentCount(2);
    }

    public static function unsafeExistingEvents(): array
    {
        return [
            'different owner' => [['extendedProperties' => ['private' => ['appointment_id' => 'other']]]],
            'deleted' => [['status' => 'cancelled']],
        ];
    }

    public function test_does_not_confirm_an_unreadable_insert_response(): void
    {
        $appointment = $this->appointment();
        Http::fake([$this->path($appointment).'*' => Http::response([])]);
        $this->expectException(CalendarException::class);
        app(GoogleCalendar::class)->save($appointment);
    }

    #[DataProvider('providerFailures')]
    public function test_classifies_provider_failures_without_exposing_response_secrets(int $status, array $body, bool $retryable): void
    {
        $connection = CalendarConnection::factory()->create();
        Http::fake([self::API.'/users/me/calendarList*' => Http::response($body, $status)]);
        try {
            app(GoogleCalendar::class)->calendars($connection);
            $this->fail('The provider failure was ignored.');
        } catch (CalendarException $exception) {
            $this->assertSame($retryable, $exception->retryable);
            $this->assertStringNotContainsString('secret', $exception->getMessage());
        }
        if ($status === 401) {
            $this->assertTrue($connection->fresh()->needs_reconnect);
        }
    }

    public static function providerFailures(): array
    {
        return [
            'rate limit' => [429, ['secret' => 'sensitive'], true],
            'server error' => [503, ['secret' => 'sensitive'], true],
            'Google quota' => [403, ['error' => ['errors' => [['reason' => 'userRateLimitExceeded']]]], true],
            'permission denied' => [403, ['secret' => 'sensitive'], false],
            'not found' => [404, [], false],
            'revoked access' => [401, [], false],
        ];
    }

    public function test_retries_a_network_timeout(): void
    {
        $connection = CalendarConnection::factory()->create();
        Http::fake([self::API.'/users/me/calendarList*' => Http::failedConnection()]);
        $this->expectExceptionMessage('taking too long');
        app(GoogleCalendar::class)->calendars($connection);
    }

    public function test_refreshes_expired_tokens_once_and_keeps_the_refresh_token(): void
    {
        $this->freezeTime();
        $connection = CalendarConnection::factory()->create(['expires_at' => now()->subMinute()]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'new-access', 'expires_in' => 3600]),
            self::API.'/users/me/calendarList*' => Http::response(['items' => []]),
        ]);
        app(GoogleCalendar::class)->calendars($connection);
        app(GoogleCalendar::class)->calendars($connection);

        $this->assertSame('test-refresh', $connection->fresh()->refresh_token);
        $this->assertSame('new-access', $connection->fresh()->access_token);
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer new-access'));
        $stored = DB::table('calendar_connections')->where('id', $connection->id)->first();
        $this->assertStringNotContainsString('new-access', $stored->access_token);
        $this->assertArrayNotHasKey('access_token', $connection->fresh()->toArray());
        $this->assertArrayNotHasKey('refresh_token', $connection->fresh()->toArray());
    }

    public function test_saves_a_rotated_refresh_token(): void
    {
        $connection = CalendarConnection::factory()->create(['expires_at' => null]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'new', 'refresh_token' => 'rotated', 'expires_in' => 3600]),
            self::API.'/users/me/calendarList*' => Http::response(['items' => []]),
        ]);
        app(GoogleCalendar::class)->calendars($connection);
        $this->assertSame('rotated', $connection->fresh()->refresh_token);
    }

    #[DataProvider('failedRefreshes')]
    public function test_handles_refresh_failures_and_preserves_reconnect_state(int $status, array $body, bool $reconnect): void
    {
        $connection = CalendarConnection::factory()->create(['expires_at' => now()->subHour()]);
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response($body, $status)]);
        try {
            app(GoogleCalendar::class)->calendars($connection);
            $this->fail('Invalid refresh was accepted.');
        } catch (CalendarException) {
            $this->assertSame($reconnect, $connection->fresh()->needs_reconnect);
        }
        Http::assertSentCount(1);
    }

    public static function failedRefreshes(): array
    {
        return ['revoked' => [400, ['error' => 'invalid_grant'], true], 'bad client' => [401, [], true], 'unavailable' => [503, [], false], 'malformed' => [200, [], false]];
    }

    public function test_refresh_timeout_is_retryable(): void
    {
        $connection = CalendarConnection::factory()->create(['expires_at' => null]);
        Http::fake(['https://oauth2.googleapis.com/token' => Http::failedConnection()]);
        $this->expectExceptionMessage('authentication is taking too long');
        app(GoogleCalendar::class)->calendars($connection);
    }

    #[DataProvider('unusableCredentials')]
    public function test_requires_reconnection_without_contacting_google(array $state): void
    {
        $connection = CalendarConnection::factory()->create($state);
        try {
            app(GoogleCalendar::class)->calendars($connection);
            $this->fail('Missing credentials were accepted.');
        } catch (CalendarException $exception) {
            $this->assertFalse($exception->retryable);
            $this->assertTrue($connection->fresh()->needs_reconnect);
        }
        Http::assertNothingSent();
    }

    public static function unusableCredentials(): array
    {
        return ['revoked' => [['needs_reconnect' => true]], 'missing refresh' => [['expires_at' => null, 'refresh_token' => null]]];
    }

    public function test_deletes_only_its_own_event(): void
    {
        $appointment = $this->appointment();
        Http::fake([$this->path($appointment).'*' => Http::sequence()->push(EventPayload::for($appointment))->push([], 204)]);
        app(GoogleCalendar::class)->delete($appointment);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_contains($request->url(), $appointment->eventId()));
    }

    public function test_sends_no_request_body_when_deleting_an_event(): void
    {
        $appointment = $this->appointment();
        $path = $this->path($appointment).'/'.$appointment->eventId();
        Http::fake([$path.'*' => Http::sequence()->push(EventPayload::for($appointment))->push([], 204)]);

        app(GoogleCalendar::class)->delete($appointment);

        Http::assertSentInOrder([
            fn ($request) => $request->method() === 'GET' && $request->url() === $path && $request->body() === '',
            fn ($request) => $request->method() === 'DELETE' && $request->url() === $path.'?sendUpdates=none' && $request->body() === '',
        ]);
    }

    #[DataProvider('absentEvents')]
    public function test_accepts_an_already_deleted_event_only_after_verifying_calendar_access(int $status, array $body): void
    {
        $appointment = $this->appointment();
        Http::fake([
            $this->path($appointment).'*' => Http::response($body, $status),
            self::API.'/users/me/calendarList/*' => Http::response(['accessRole' => 'writer']),
        ]);
        app(GoogleCalendar::class)->delete($appointment);
        Http::assertSentCount(2);
    }

    public static function absentEvents(): array
    {
        return ['not found' => [404, []], 'gone' => [410, []], 'tombstone' => [200, ['status' => 'cancelled']]];
    }

    public function test_handles_an_event_deleted_between_lookup_and_delete(): void
    {
        $appointment = $this->appointment();
        Http::fake([
            $this->path($appointment).'*' => Http::sequence()->push(EventPayload::for($appointment))->push([], 410),
            self::API.'/users/me/calendarList/*' => Http::response(['accessRole' => 'owner']),
        ]);
        app(GoogleCalendar::class)->delete($appointment);
        Http::assertSentCount(3);
    }

    #[DataProvider('lostCalendarAccess')]
    public function test_does_not_mistake_lost_access_for_successful_deletion(int $status, array $body): void
    {
        $appointment = $this->appointment();
        Http::fake([
            $this->path($appointment).'*' => Http::response([], 404),
            self::API.'/users/me/calendarList/*' => Http::response($body, $status),
        ]);
        $this->expectException(CalendarException::class);
        app(GoogleCalendar::class)->delete($appointment);
    }

    public static function lostCalendarAccess(): array
    {
        return ['hidden calendar' => [404, []], 'read only' => [200, ['accessRole' => 'reader']]];
    }
}
