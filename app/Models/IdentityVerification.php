<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'document_type',
    'document_number',
    'status',
    'document_front_path',
    'document_back_path',
    'selfie_path',
    'liveness_video_path',
    'liveness_check_status',
    'liveness_score',
    'face_match_status',
    'face_match_score',
    'review_notes',
    'submitted_at',
    'reviewed_at',
])]
class IdentityVerification extends Model
{
    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'liveness_score' => 'decimal:2',
            'face_match_score' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
