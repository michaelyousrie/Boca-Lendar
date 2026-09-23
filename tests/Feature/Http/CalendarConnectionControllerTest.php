<?php

namespace Tests\Feature\Http;

use App\Calendar\CalendarException;
use App\Calendar\CalendarProvider;
use App\Models\CalendarConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CalendarConnectionControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function googleConfig(): void
    {
        config(['services.google.client_id' => 'client-id', 'services.google.client_secret' => 'client-secret', 'services.google.redirect' => 'http://localhost/calendar/google/callback']);
    }

    private function fakeGoogle(array $changes = []): GoogleUser
    {
        $account = (new GoogleUser)->map(['id' => 'google-account', 'email' => 'google@example.com']);
        $account->token = 'access-secret';
        $account->refreshToken = 'refresh-secret';
        $account->expiresIn = 3600;
        $account->approvedScopes = ['https://www.googleapis.com/auth/calendar.calendarlist.readonly', 'https://www.googleapis.com/auth/calendar.events'];
        foreach ($changes as $key => $value) {
            $account->$key = $value;
        }
        $driver = Mockery::mock(GoogleProvider::class);
        $driver->shouldReceive('enablePKCE', 'setHttpClient')->andReturnSelf();
        $driver->shouldReceive('user')->once()->andReturn($account);
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

        return $account;
    }

    private function completeOAuth(): TestResponse
    {
        return $this->withSession(['google_started_at' => now()->timestamp])->get('/calendar/google/callback?code=test&state=test');
    }

    public function test_redirect_requires_configuration_and_requests_offline_access_with_state_and_pkce(): void
    {
        $this->googleConfig();
        $this->actingAs(User::factory()->create());
        config(['services.google.client_id' => null]);
        $this->get('/calendar/google')->assertRedirect('/appointments')->assertSessionHas('error');
        config(['services.google.client_id' => 'client-id']);
        $response = $this->get('/calendar/google')->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertNotEmpty($query['state']);
        $this->assertStringContainsString('calendar.events', $query['scope']);
    }

    #[DataProvider('invalidCallbacks')]
    public function test_rejects_missing_expired_and_denied_oauth_attempts(?int $age, string $query): void
    {
        $this->googleConfig();
        $this->actingAs(User::factory()->create());
        if ($age !== null) {
            $this->withSession(['google_started_at' => now()->subSeconds($age)->timestamp]);
        }
        $this->get('/calendar/google/callback'.$query)->assertRedirect('/appointments')->assertSessionHas('error');
        $this->assertDatabaseCount('calendar_connections', 0);
    }

    public static function invalidCallbacks(): array
    {
        return ['missing attempt' => [null, ''], 'expired' => [601, ''], 'denied' => [0, '?error=access_denied']];
    }

    public function test_rejects_a_forged_oauth_state(): void
    {
        $this->googleConfig();
        $this->actingAs(User::factory()->create())->withSession(['google_started_at' => now()->timestamp, 'state' => 'expected'])
            ->get('/calendar/google/callback?state=forged&code=irrelevant')->assertSessionHas('error');
        $this->assertDatabaseCount('calendar_connections', 0);
    }

    public function test_callback_stores_credentials_and_lists_calendars_without_changing_app_identity(): void
    {
        $this->googleConfig();
        $user = User::factory()->create();
        $this->fakeGoogle();
        $this->mock(CalendarProvider::class)->shouldReceive('calendars')->once()->andReturn([['id' => 'a', 'name' => 'Work', 'timezone' => 'UTC', 'writable' => true]]);
        $this->actingAs($user);
        $this->completeOAuth()->assertRedirect('/appointments')->assertSessionHas('success');
        $connection = $user->connection()->sole();
        $this->assertSame('refresh-secret', $connection->refresh_token);
        $this->assertSame('google-account', $connection->account_id);
        $this->assertCount(1, $connection->calendars);
        $this->assertAuthenticatedAs($user);
        $this->get('/calendar/google/callback?code=test')->assertSessionHas('error');
    }

    public function test_reconnection_preserves_the_original_refresh_token_and_selected_calendar(): void
    {
        $this->googleConfig();
        $connection = CalendarConnection::factory()->create(['account_id' => 'google-account', 'needs_reconnect' => true]);
        $this->fakeGoogle(['refreshToken' => null]);
        $this->mock(CalendarProvider::class)->shouldReceive('calendars')->andReturn([]);
        $this->actingAs(User::find($connection->user_id));
        $this->completeOAuth()->assertSessionHas('success');
        $fresh = $connection->fresh();
        $this->assertSame('test-refresh', $fresh->refresh_token);
        $this->assertSame($connection->selected_calendar_id, $fresh->selected_calendar_id);
        $this->assertFalse($fresh->needs_reconnect);
    }

    #[DataProvider('incompleteAccounts')]
    public function test_rejects_incomplete_google_permissions_or_account_details(array $changes): void
    {
        $this->googleConfig();
        $this->fakeGoogle($changes);
        $this->actingAs(User::factory()->create());
        $this->completeOAuth()->assertSessionHas('error');
        $this->assertDatabaseCount('calendar_connections', 0);
    }

    public static function incompleteAccounts(): array
    {
        return ['missing refresh' => [['refreshToken' => null]], 'partial scopes' => [['approvedScopes' => []]], 'no email' => [['email' => null]], 'no token' => [['token' => null]], 'bad expiry' => [['expiresIn' => 0]]];
    }

    public function test_does_not_replace_a_linked_google_account_with_another_account(): void
    {
        $this->googleConfig();
        $connection = CalendarConnection::factory()->create(['account_id' => 'original']);
        $this->fakeGoogle();
        $this->actingAs(User::find($connection->user_id));
        $this->completeOAuth()->assertSessionHas('error');
        $this->assertSame('original', $connection->fresh()->account_id);
    }

    public function test_provider_failure_after_oauth_leaves_a_recoverable_connection(): void
    {
        $this->googleConfig();
        $this->fakeGoogle();
        $this->mock(CalendarProvider::class)->shouldReceive('calendars')->andThrow(new CalendarException('Google is unavailable.'));
        $this->actingAs(User::factory()->create());
        $this->completeOAuth()->assertSessionHas('error', 'Google is unavailable.');
        $this->assertDatabaseCount('calendar_connections', 1);
    }

    public function test_refreshes_calendar_options(): void
    {
        $connection = CalendarConnection::factory()->create();
        $this->mock(CalendarProvider::class)->shouldReceive('calendars')->once()->andReturn([['id' => 'fresh', 'name' => 'Fresh calendar', 'timezone' => 'UTC', 'writable' => true]]);
        $this->actingAs(User::find($connection->user_id))->post('/calendar/refresh')->assertSessionHas('success');
        $this->assertSame('fresh', $connection->fresh()->calendars[0]['id']);
    }

    public function test_refresh_failures_leave_the_existing_selection_intact(): void
    {
        $connection = CalendarConnection::factory()->create();
        $this->mock(CalendarProvider::class)->shouldReceive('calendars')->once()->andThrow(new CalendarException('Try later.'));
        $this->actingAs(User::find($connection->user_id))->post('/calendar/refresh')->assertSessionHas('error', 'Try later.');
        $this->assertSame($connection->selected_calendar_id, $connection->fresh()->selected_calendar_id);
    }
}
