<?php

use App\Models\Conversation;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
}, ['guards' => ['sanctum']]);

Broadcast::channel('conversation.{conversationId}', function ($user, int $conversationId): bool {
    return Conversation::query()
        ->whereKey($conversationId)
        ->where(function ($query) use ($user): void {
            $query
                ->where('driver_id', $user->id)
                ->orWhere('passenger_id', $user->id);
        })
        ->exists();
}, ['guards' => ['sanctum']]);
