<?php

namespace App\Http\Controllers;

use App\Calendar\CalendarException;
use App\Calendar\CalendarProvider;
use App\Calendar\CalendarSync;
use App\Models\CalendarConnection;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\InvalidStateException;

class CalendarConnectionController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        if (! config('services.google.client_id') || ! config('services.google.client_secret')) {
            return redirect()->route('dashboard')->with('error', 'Google connection is not configured. Add the client credentials in the server settings.');
        }
        $request->session()->put('google_started_at', now()->timestamp);

        return $this->google()->scopes([
            'https://www.googleapis.com/auth/calendar.calendarlist.readonly',
            'https://www.googleapis.com/auth/calendar.events',
        ])->with(['access_type' => 'offline', 'prompt' => 'consent'])->redirect();
    }

    public function callback(Request $request, CalendarProvider $provider, CalendarSync $events): RedirectResponse
    {
        $started = $request->session()->pull('google_started_at');
        if (! $started || now()->timestamp - $started > 600 || $request->has('error')) {
            $request->session()->forget(['state', 'code_verifier']);

            return redirect()->route('dashboard')->with('error', 'Google connection was cancelled or expired. Try connecting again.');
        }
        try {
            $account = $this->google()->user();
            $requiredScopes = ['https://www.googleapis.com/auth/calendar.calendarlist.readonly', 'https://www.googleapis.com/auth/calendar.events'];
            if (array_diff($requiredScopes, $account->approvedScopes)) {
                throw new CalendarException('Accept both calendar permissions so appointments can be synchronized.', false);
            }
            if (! $account->getId() || ! filter_var($account->getEmail(), FILTER_VALIDATE_EMAIL) || ! $account->token || $account->expiresIn <= 0) {
                throw new CalendarException('Google returned incomplete account details. Try connecting again.', false);
            }
            $connection = DB::transaction(function () use ($request, $account) {
                User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                $connection = $request->user()->connection()->lockForUpdate()->first();
                if ($connection && ($connection->provider !== 'google' || $connection->account_id !== $account->getId())) {
                    throw new CalendarException('Reconnect the same Google account so existing bookings remain manageable.', false);
                }
                if (! $account->refreshToken && ! $connection?->refresh_token) {
                    throw new CalendarException('Google did not grant offline access. Connect again and accept calendar permissions.', false);
                }

                return CalendarConnection::updateOrCreate(['user_id' => $request->user()->id], [
                    'provider' => 'google', 'account_id' => $account->getId(), 'email' => $account->getEmail(),
                    'access_token' => $account->token,
                    'refresh_token' => $account->refreshToken ?: $connection?->refresh_token,
                    'expires_at' => now()->addSeconds($account->expiresIn), 'needs_reconnect' => false,
                ]);
            });
            $connection->update(['calendars' => $provider->calendars($connection)]);
            foreach ($connection->calendars as $calendar) {
                $events->requestSync($connection, $calendar);
            }
        } catch (InvalidStateException|GuzzleException) {
            return redirect()->route('dashboard')->with('error', 'Google connection could not be completed. Try connecting again.');
        } catch (CalendarException $exception) {
            return redirect()->route('dashboard')->with('error', $exception->getMessage());
        }

        return redirect()->route('dashboard')->with('success', 'Google connected.');
    }

    public function refresh(Request $request, CalendarProvider $provider, CalendarSync $events): RedirectResponse
    {
        $connection = $request->user()->connection()->firstOrFail();
        try {
            $connection->update(['calendars' => $provider->calendars($connection)]);
            foreach ($connection->calendars as $calendar) {
                $events->requestSync($connection, $calendar);
            }
        } catch (CalendarException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Calendar list refreshed.');
    }

    private function google(): GoogleProvider
    {
        return Socialite::driver('google')->enablePKCE()->setHttpClient(new Client(['timeout' => 10, 'connect_timeout' => 3]));
    }
}
