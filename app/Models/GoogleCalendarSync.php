<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleCalendarSync extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['sync_token', 'request_token'];

    protected function casts(): array
    {
        return ['import_year' => 'integer', 'requested_at' => 'immutable_datetime', 'synced_at' => 'immutable_datetime'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(CalendarConnection::class, 'calendar_connection_id');
    }

    public function syncing(): bool
    {
        return $this->request_token !== null && $this->requested_at->greaterThan(now()->subMinutes(3));
    }
}
