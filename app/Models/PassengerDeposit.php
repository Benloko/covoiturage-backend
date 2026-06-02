<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'amount_fcfa',
    'network',
    'phone',
    'status',
    'provider',
    'provider_transaction_id',
    'provider_reference',
    'provider_status',
    'provider_failure_reason',
    'provider_payload',
    'provider_last_webhook_at',
    'reference',
    'requested_at',
    'processed_at',
])]
class PassengerDeposit extends Model
{
    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'processed_at' => 'datetime',
            'provider_payload' => 'array',
            'provider_last_webhook_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
