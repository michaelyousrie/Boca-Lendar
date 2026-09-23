<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BookingCalendar extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public static function localFor(User $user): self
    {
        return self::firstOrCreate(['provider' => 'local', 'external_id' => (string) $user->id], [
            'name' => 'Local appointments', 'timezone' => 'Africa/Cairo',
        ]);
    }
}
