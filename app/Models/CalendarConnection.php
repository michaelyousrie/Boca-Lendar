<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class CalendarConnection extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'immutable_datetime',
            'calendars' => 'array',
            'needs_reconnect' => 'boolean',
        ];
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(BookingCalendar::class, 'selected_calendar_id');
    }

    public function resolveCalendar(string $calendarId): BookingCalendar
    {
        $selected = collect($this->calendars)->firstWhere('id', $calendarId);
        if (! $selected || ! $selected['writable']) {
            throw ValidationException::withMessages(['calendar_id' => 'Choose a calendar you have permission to edit. Refresh calendars if the list has changed.']);
        }

        return BookingCalendar::firstOrCreate([
            'provider' => 'google', 'external_id' => $selected['id'],
        ], ['name' => $selected['name'], 'timezone' => $selected['timezone']]);
    }
}
