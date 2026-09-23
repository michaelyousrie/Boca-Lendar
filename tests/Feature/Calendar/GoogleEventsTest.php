<?php

namespace Tests\Feature\Calendar;

use App\Calendar\CalendarException;
use App\Calendar\GoogleCalendar;
use App\Models\CalendarConnection;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoogleEventsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const URL = 'https://www.googleapis.com/calendar/v3/calendars/shared%40example.com/events*';

    private function timed(string $id = 'meeting', string $start = '2026-11-01T01:30:00-04:00', string $end = '2026-11-01T01:30:00-05:00'): array
    {
        return ['id' => $id, 'summary' => 'Design review', 'start' => ['dateTime' => $start], 'end' => ['dateTime' => $end]];
    }

    private function events(): array
    {
        $start = CarbonImmutable::parse('2026-11-01', 'America/New_York');

        return app(GoogleCalendar::class)->changes(CalendarConnection::factory()->create(), 'shared@example.com', null, $start, $start->addDay())['events'];
    }

    public function test_reads_all_pages_expands_recurrences_and_deduplicates_repeated_instances(): void
    {
        $meeting = $this->timed() + ['htmlLink' => 'https://www.google.com/calendar/event?eid=meeting'];
        $instance = $this->timed('series_20261101T120000Z', '2026-11-01T12:00:00.000Z', '2026-11-01T13:00:00Z') + ['recurringEventId' => 'series'];
        Http::fake([self::URL => Http::sequence()->push(['nextSyncToken' => 'next-sync', 'items' => [$meeting], 'nextPageToken' => 'next'])
            ->push(['nextSyncToken' => 'next-sync', 'items' => [$meeting, $instance, ['id' => 'deleted', 'status' => 'cancelled']]])]);

        $events = $this->events();

        $this->assertCount(2, $events);
        $this->assertSame(['id' => 'meeting', 'title' => 'Design review', 'starts_at' => '2026-11-01T05:30:00+00:00',
            'ends_at' => '2026-11-01T06:30:00+00:00', 'all_day' => false,
            'url' => 'https://www.google.com/calendar/event?eid=meeting', 'timezone' => null, 'customer_name' => null, 'customer_email' => null, 'appointment_id' => null, 'recurring_event_id' => null], $events[0]);
        $this->assertSame('series_20261101T120000Z', $events[1]['id']);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request['singleEvents'] === 'false'
            && $request['showDeleted'] === 'true' && $request['timeZone'] === 'America/New_York'
            && $request['timeMin'] === '2026-11-01T00:00:00-04:00' && $request['timeMax'] === '2026-11-02T00:00:00-05:00'
            && ($request['pageToken'] ?? null) === 'next' && $request->hasHeader('Authorization', 'Bearer test-access'));
    }

    public function test_normalizes_overnight_and_floating_all_day_events_without_discarding_changes_outside_the_import_range(): void
    {
        Http::fake([self::URL => Http::response(['nextSyncToken' => 'next-sync', 'items' => [
            $this->timed('ends-at-midnight', '2026-10-31T23:00:00-04:00', '2026-11-01T00:00:00-04:00'),
            $this->timed('starts-next-day', '2026-11-02T00:00:00-05:00', '2026-11-02T01:00:00-05:00'),
            $this->timed('overnight', '2026-10-31T23:30:00-04:00', '2026-11-01T00:30:00-04:00'),
            ['id' => 'holiday', 'start' => ['date' => '2026-11-01'], 'end' => ['date' => '2026-11-02']],
            ['id' => 'multi-day', 'summary' => 'Trip', 'start' => ['date' => '2026-10-30'], 'end' => ['date' => '2026-11-03']],
            ['id' => 'yesterday', 'start' => ['date' => '2026-10-31'], 'end' => ['date' => '2026-11-01']],
            ['id' => 'tomorrow', 'start' => ['date' => '2026-11-02'], 'end' => ['date' => '2026-11-03']],
        ]])]);

        $events = $this->events();

        $this->assertCount(7, $events);
        $this->assertSame(['id' => 'holiday', 'title' => 'Untitled appointment', 'starts_at' => '2026-11-01', 'ends_at' => '2026-11-02',
            'all_day' => true, 'url' => null, 'timezone' => null, 'customer_name' => null, 'customer_email' => null, 'appointment_id' => null, 'recurring_event_id' => null], $events[3]);
        Http::assertSentCount(1);
    }

    #[DataProvider('unsafeLinks')]
    public function test_does_not_expose_untrusted_event_links_or_foreign_ownership_markers(mixed $url): void
    {
        Http::fake([self::URL => Http::response(['nextSyncToken' => 'next-sync', 'items' => [$this->timed() + ['htmlLink' => $url,
            'extendedProperties' => ['private' => ['appointment_id' => 'some-other-app-id']]]]])]);

        $event = $this->events()[0];

        $this->assertNull($event['url']);
        $this->assertNull($event['appointment_id']);
        Http::assertSentCount(1);
    }

    public static function unsafeLinks(): array
    {
        return [['javascript:alert(1)'], ['https://evil.example/event'], ['http://calendar.google.com/event'], [['url' => 'bad']]];
    }

    #[DataProvider('badEvents')]
    public function test_rejects_malformed_responses_without_silently_showing_an_incomplete_agenda(mixed $body): void
    {
        Http::fake([self::URL => Http::response($body)]);
        $this->expectException(CalendarException::class);
        $this->events();
    }

    public static function badEvents(): array
    {
        $timed = ['id' => 'event', 'start' => ['dateTime' => '2026-11-01T12:00:00Z'], 'end' => ['dateTime' => '2026-11-01T13:00:00Z']];

        return [
            'missing items' => [[]], 'object items' => [['nextSyncToken' => 'next-sync', 'items' => ['bad' => $timed]]],
            'scalar event' => [['nextSyncToken' => 'next-sync', 'items' => [false]]], 'missing fields' => [['nextSyncToken' => 'next-sync', 'items' => [['id' => 'bad']]]],
            'relative datetime' => [['nextSyncToken' => 'next-sync', 'items' => [array_replace($timed, ['start' => ['dateTime' => 'tomorrow']])]]],
            'invalid date' => [['nextSyncToken' => 'next-sync', 'items' => [['id' => 'bad', 'start' => ['date' => '2026-02-30'], 'end' => ['date' => '2026-03-01']]]]],
            'mixed date kinds' => [['nextSyncToken' => 'next-sync', 'items' => [array_replace($timed, ['end' => ['date' => '2026-11-02']])]]],
            'backwards' => [['nextSyncToken' => 'next-sync', 'items' => [array_replace($timed, ['end' => ['dateTime' => '2026-11-01T11:00:00Z']])]]],
            'zero length all-day' => [['nextSyncToken' => 'next-sync', 'items' => [['id' => 'bad', 'start' => ['date' => '2026-11-01'], 'end' => ['date' => '2026-11-01']]]]],
            'repeated page' => [['nextSyncToken' => 'next-sync', 'items' => [], 'nextPageToken' => 'same']],
            'invalid token' => [['nextSyncToken' => 'next-sync', 'items' => [], 'nextPageToken' => ['bad']]],
            'empty token' => [['nextSyncToken' => 'next-sync', 'items' => [], 'nextPageToken' => '']],
        ];
    }

    public function test_changed_series_expand_only_the_requested_year_and_include_moved_exceptions(): void
    {
        $start = CarbonImmutable::parse('2026-01-01', 'UTC');
        $exception = $this->timed('exception') + ['recurringEventId' => 'series'];
        Http::fake([
            '*/events?*' => Http::response(['items' => [$exception, ['id' => 'series', 'recurrence' => ['RRULE:FREQ=DAILY']]], 'nextSyncToken' => 'next']),
            '*/events/series/instances?*' => Http::sequence()->push(['items' => [$this->timed('first')], 'nextPageToken' => 'second'])
                ->push(['items' => [$this->timed('exception'), ['id' => 'cancelled-instance', 'status' => 'cancelled']]]),
        ]);
        $result = app(GoogleCalendar::class)->changes(CalendarConnection::factory()->create(), 'calendar', 'saved', $start, $start->addYear());
        $this->assertSame(['first', 'exception'], array_column($result['events'], 'id'));
        $this->assertSame(['series'], $result['series']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/instances?') && $request['timeMin'] === '2026-01-01T00:00:00+00:00' && $request['timeMax'] === '2027-01-01T00:00:00+00:00');
    }

    public function test_a_deleted_series_is_not_expanded_even_if_an_earlier_page_listed_it(): void
    {
        Http::fake([self::URL => Http::response(['items' => [['id' => 'series', 'recurrence' => ['RRULE:FREQ=DAILY']], ['id' => 'series', 'status' => 'cancelled']], 'nextSyncToken' => 'next'])]);
        $this->assertSame([], $this->events());
        Http::assertSentCount(1);
    }

    public function test_missing_final_sync_token_is_not_treated_as_a_successful_import(): void
    {
        Http::fake([self::URL => Http::response(['items' => []])]);
        $this->expectException(CalendarException::class);
        $this->expectExceptionMessage('sync token');
        $this->events();
    }

    public function test_numeric_event_ids_and_opaque_tokens_are_preserved_as_strings(): void
    {
        $start = CarbonImmutable::parse('2026-01-01', 'UTC');
        Http::fake([self::URL => Http::sequence()->push(['items' => [], 'nextPageToken' => '0'])
            ->push(['items' => [['id' => '12345', 'status' => 'cancelled']], 'nextSyncToken' => 'next'])]);
        $result = app(GoogleCalendar::class)->changes(CalendarConnection::factory()->create(), 'shared@example.com', '0', $start, $start->addYear());
        $this->assertSame(['12345'], $result['deleted']);
        Http::assertSent(fn ($request) => $request['syncToken'] === '0' && ($request['pageToken'] ?? null) === '0');
    }

    public function test_a_later_page_failure_never_returns_partial_results(): void
    {
        Http::fake([self::URL => Http::sequence()->push(['items' => [$this->timed()], 'nextPageToken' => 'next'])->push([], 503)]);
        $this->expectException(CalendarException::class);
        $this->events();
    }
}
