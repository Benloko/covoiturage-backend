<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'vehicle_type_scope',
    'document_type',
    'document_label',
    'document_number',
    'expires_at',
    'document_photo_path',
    'status',
    'review_notes',
    'submitted_at',
])]
class DriverVehicleDocument extends Model
{
    protected function casts(): array
    {
        return [
            'expires_at' => 'date',
            'submitted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

