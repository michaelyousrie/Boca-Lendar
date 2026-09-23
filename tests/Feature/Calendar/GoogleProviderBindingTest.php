<?php

namespace Tests\Feature\Calendar;

use App\Calendar\CalendarProvider;
use App\Models\CalendarConnection;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleProviderBindingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_calendar_provider_uses_the_google_adapter(): void
    {
        $connection = CalendarConnection::factory()->create();
        Http::fake(['https://www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::response(['items' => []])]);
        $this->assertSame([], app(CalendarProvider::class)->calendars($connection));
        Http::assertSentCount(1);
    }
}
