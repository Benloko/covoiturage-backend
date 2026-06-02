<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'trip_id',
    'passenger_id',
    'status',
    'seats_reserved',
    'booked_price_fcfa',
    'booked_at',
    'cancelled_at',
    'driver_completed_at',
    'passenger_completion_status',
    'passenger_completion_confirmed_at',
    'payout_status',
    'payout_amount_fcfa',
    'payout_credited_at',
    'passenger_rating',
    'passenger_review',
    'rated_at',
])]
class Booking extends Model
{
    protected function casts(): array
    {
        return [
            'booked_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'driver_completed_at' => 'datetime',
            'passenger_completion_confirmed_at' => 'datetime',
            'payout_credited_at' => 'datetime',
            'rated_at' => 'datetime',
        ];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'passenger_id');
    }
}


