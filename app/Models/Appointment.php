<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    use HasFactory;
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'next_sync_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
            'holds_slot' => 'boolean',
            'all_day' => 'boolean',
            'conflict_checked' => 'boolean',
        ];
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(BookingCalendar::class, 'booking_calendar_id');
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(CalendarConnection::class, 'calendar_connection_id');
    }

    public function scopeOverlapping(Builder $query, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $query->where(fn ($query) => $query
            ->where(fn ($query) => $query->where('all_day', false)->where('starts_at', '<', $end->utc())->where('ends_at', '>', $start->utc()))
            ->orWhere(fn ($query) => $query->where('all_day', true)
                ->whereRaw('(starts_at AT TIME ZONE appointments.timezone)::date < ?::date', [$end->toDateString()])
                ->whereRaw('(ends_at AT TIME ZONE appointments.timezone)::date > ?::date', [$start->toDateString()])));
    }

    public function scopeConflictingImported(Builder $query, int $calendarId, CarbonImmutable $start, CarbonImmutable $end, ?string $exceptId = null): void
    {
        $query->where('booking_calendar_id', $calendarId)
            ->where('conflict_checked', false)
            ->where(fn (Builder $query) => $query->where('status', 'scheduled')
                ->orWhere(fn (Builder $query) => $query->where('status', 'cancelled')->whereIn('sync_status', ['pending', 'failed'])))
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }
    }

    public function displayStart(string $timezone): CarbonImmutable
    {
        return $this->all_day ? CarbonImmutable::parse($this->starts_at->setTimezone($this->timezone)->toDateString(), $timezone)
            : $this->starts_at->setTimezone($timezone);
    }

    public function displayEnd(string $timezone): CarbonImmutable
    {
        return $this->all_day ? CarbonImmutable::parse($this->ends_at->setTimezone($this->timezone)->toDateString(), $timezone)
            : $this->ends_at->setTimezone($timezone);
    }

    public function writable(): bool
    {
        return ! $this->calendar_connection_id || (bool) (collect($this->getRelationValue('connection')->calendars)->firstWhere('id', $this->calendar->external_id)['writable'] ?? false);
    }

    public function revision(): string
    {
        return hash('sha256', json_encode($this->only([
            'title', 'customer_name', 'customer_email', 'starts_at', 'ends_at', 'timezone',
            'status', 'booking_calendar_id', 'calendar_connection_id', 'all_day',
        ]), JSON_THROW_ON_ERROR));
    }

    public function eventId(): string
    {
        return $this->google_event_id ?? str_replace('-', '', $this->id);
    }
}
