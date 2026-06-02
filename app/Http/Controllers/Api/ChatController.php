<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Trip;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChatController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $limit = (int) ($validated['limit'] ?? 30);

        $query = $this->conversationsForUser($user);

        $total = (clone $query)->count();

        $conversations = $query
            ->with([
                'trip',
                'driver',
                'passenger',
                'latestMessage.sender',
            ])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $data = $conversations
            ->map(fn (Conversation $conversation): array => $this->serializeConversationSummary($conversation, $user))
            ->all();

        return response()->json([
            'data' => $data,
            'meta' => [
                'total' => $total,
                'unread_total' => array_sum(array_map(static fn (array $item): int => (int) ($item['unread_count'] ?? 0), $data)),
            ],
        ]);
    }

    public function create(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'target_user_id' => ['required', 'integer', 'exists:users,id', 'different:'.(string) $user->id],
            'trip_id' => ['required', 'integer', 'exists:trips,id'],
        ]);

        /** @var User $target */
        $target = User::query()->findOrFail((int) $validated['target_user_id']);

        if ($user->role === 'driver') {
            if ($target->role !== 'passenger') {
                throw ValidationException::withMessages([
                    'target_user_id' => ['Un conducteur peut discuter uniquement avec un passager.'],
                ]);
            }

            $driver = $user;
            $passenger = $target;
        } elseif ($user->role === 'passenger') {
            if ($target->role !== 'driver') {
                throw ValidationException::withMessages([
                    'target_user_id' => ['Un passager peut discuter uniquement avec un conducteur.'],
                ]);
            }

            $driver = $target;
            $passenger = $user;
        } else {
            return response()->json([
                'message' => 'Acces reserve aux passagers et conducteurs.',
            ], 403);
        }

        /** @var Trip $trip */
        $trip = Trip::query()->findOrFail((int) $validated['trip_id']);

        if ((int) $trip->driver_id !== (int) $driver->id) {
            throw ValidationException::withMessages([
                'trip_id' => ['Le conducteur selectionne ne correspond pas a ce trajet.'],
            ]);
        }

        $bookingExists = Booking::query()
            ->where('trip_id', $trip->id)
            ->where('passenger_id', $passenger->id)
            ->exists();

        if (! $bookingExists) {
            throw ValidationException::withMessages([
                'trip_id' => ['Ce passager n a pas de reservation sur ce trajet.'],
            ]);
        }

        $conversation = Conversation::query()->firstOrCreate(
            [
                'trip_id' => $trip->id,
                'driver_id' => $driver->id,
                'passenger_id' => $passenger->id,
            ],
            [
                'last_message_at' => null,
                'driver_last_read_at' => $user->id === $driver->id ? now() : null,
                'passenger_last_read_at' => $user->id === $passenger->id ? now() : null,
            ]
        );

        $this->markConversationReadForUser($conversation, $user);

        $conversation = $conversation->fresh([
            'trip',
            'driver',
            'passenger',
            'latestMessage.sender',
        ]);

        return response()->json([
            'message' => 'Conversation prete.',
            'conversation' => $this->serializeConversationSummary($conversation, $user),
        ], 201);
    }

    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->isParticipant($conversation, $user)) {
            return response()->json([
                'message' => 'Cette conversation ne vous appartient pas.',
            ], 403);
        }

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'after_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $limit = (int) ($validated['limit'] ?? 100);
        $afterId = (int) ($validated['after_id'] ?? 0);

        $messagesQuery = ConversationMessage::query()
            ->with('sender')
            ->where('conversation_id', $conversation->id);

        if ($afterId > 0) {
            $messages = $messagesQuery
                ->where('id', '>', $afterId)
                ->orderBy('id')
                ->limit($limit)
                ->get()
                ->values();
        } else {
            $messages = $messagesQuery
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->reverse()
                ->values();
        }

        $lastMessageId = $messages->isNotEmpty()
            ? (int) $messages->last()->id
            : ($afterId > 0 ? $afterId : null);

        $this->markConversationReadForUser($conversation, $user);

        $conversation = $conversation->fresh([
            'trip',
            'driver',
            'passenger',
            'latestMessage.sender',
        ]);

        return response()->json([
            'conversation' => $this->serializeConversationSummary($conversation, $user),
            'messages' => $messages
                ->map(fn (ConversationMessage $message): array => $this->serializeMessage($message, $user))
                ->all(),
            'meta' => [
                'after_id' => $afterId > 0 ? $afterId : null,
                'count' => $messages->count(),
                'last_message_id' => $lastMessageId,
            ],
        ]);
    }

    public function sendMessage(Request $request, Conversation $conversation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->isParticipant($conversation, $user)) {
            return response()->json([
                'message' => 'Cette conversation ne vous appartient pas.',
            ], 403);
        }

        $validated = $request->validate([
            'message' => ['required', 'string', 'min:1', 'max:2000'],
        ]);

        $messageBody = trim((string) $validated['message']);

        if ($messageBody === '') {
            throw ValidationException::withMessages([
                'message' => ['Le message ne peut pas etre vide.'],
            ]);
        }

        $message = DB::transaction(function () use ($conversation, $user, $messageBody): ConversationMessage {
            /** @var Conversation $lockedConversation */
            $lockedConversation = Conversation::query()
                ->lockForUpdate()
                ->whereKey($conversation->id)
                ->firstOrFail();

            $message = ConversationMessage::query()->create([
                'conversation_id' => $lockedConversation->id,
                'sender_id' => $user->id,
                'message' => $messageBody,
            ]);

            $fields = [
                'last_message_at' => now(),
            ];

            if ((int) $lockedConversation->driver_id === (int) $user->id) {
                $fields['driver_last_read_at'] = now();
            }

            if ((int) $lockedConversation->passenger_id === (int) $user->id) {
                $fields['passenger_last_read_at'] = now();
            }

            $lockedConversation->forceFill($fields)->save();

            return $message->fresh('sender');
        });

        $conversation = $conversation->fresh([
            'trip',
            'driver',
            'passenger',
            'latestMessage.sender',
        ]);

        $recipient = (int) $conversation->driver_id === (int) $user->id
            ? $conversation->passenger
            : $conversation->driver;

        $routeLabel = ($conversation->trip?->from_city ?: 'Depart').' -> '.($conversation->trip?->to_city ?: 'Destination');

        $this->createUserNotification(
            $recipient,
            'chat_message',
            'Nouveau message',
            $user->name.' vous a envoye un message pour '.$routeLabel.'.',
            [
                'conversation_id' => $conversation->id,
                'trip_id' => $conversation->trip_id,
                'sender_id' => $user->id,
            ]
        );

        $this->broadcastConversationMessage($conversation, $message);

        return response()->json([
            'message' => 'Message envoye.',
            'conversation' => $this->serializeConversationSummary($conversation, $user),
            'chat_message' => $this->serializeMessage($message, $user),
        ], 201);
    }

    public function markRead(Request $request, Conversation $conversation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->isParticipant($conversation, $user)) {
            return response()->json([
                'message' => 'Cette conversation ne vous appartient pas.',
            ], 403);
        }

        $this->markConversationReadForUser($conversation, $user);

        $conversation = $conversation->fresh([
            'trip',
            'driver',
            'passenger',
            'latestMessage.sender',
        ]);

        return response()->json([
            'message' => 'Conversation marquee comme lue.',
            'conversation' => $this->serializeConversationSummary($conversation, $user),
        ]);
    }

    private function conversationsForUser(User $user): Builder
    {
        if ($user->role === 'driver') {
            return Conversation::query()->where('driver_id', $user->id);
        }

        if ($user->role === 'passenger') {
            return Conversation::query()->where('passenger_id', $user->id);
        }

        return Conversation::query()->whereRaw('1 = 0');
    }

    private function isParticipant(Conversation $conversation, User $user): bool
    {
        return (int) $conversation->driver_id === (int) $user->id
            || (int) $conversation->passenger_id === (int) $user->id;
    }

    private function markConversationReadForUser(Conversation $conversation, User $user): void
    {
        $fields = [];

        if ((int) $conversation->driver_id === (int) $user->id) {
            $fields['driver_last_read_at'] = now();
        }

        if ((int) $conversation->passenger_id === (int) $user->id) {
            $fields['passenger_last_read_at'] = now();
        }

        if ($fields === []) {
            return;
        }

        $conversation->forceFill($fields)->save();
    }

    private function serializeConversationSummary(Conversation $conversation, User $currentUser): array
    {
        $isDriver = (int) $conversation->driver_id === (int) $currentUser->id;

        $counterpart = $isDriver ? $conversation->passenger : $conversation->driver;
        $lastMessage = $conversation->latestMessage;
        $trip = $conversation->trip;

        return [
            'id' => $conversation->id,
            'trip' => [
                'id' => $trip?->id,
                'from' => $trip?->from_label ?: $trip?->from_city,
                'to' => $trip?->to_label ?: $trip?->to_city,
                'date' => $trip?->departure_at?->format('Y-m-d'),
                'time' => $trip?->departure_at?->format('H:i'),
                'status' => $trip?->status,
            ],
            'counterpart' => [
                'id' => $counterpart?->id,
                'name' => $counterpart?->name ?? 'Utilisateur',
                'phone' => $counterpart?->phone,
                'role' => $counterpart?->role,
                'avatar' => $this->initialFromName($counterpart?->name),
                'avatar_url' => $this->avatarUrl($counterpart?->avatar_path),
            ],
            'last_message' => $lastMessage ? [
                'id' => $lastMessage->id,
                'text' => $lastMessage->message,
                'created_at' => $lastMessage->created_at,
                'sender_id' => $lastMessage->sender_id,
                'sender_name' => $lastMessage->sender?->name,
            ] : null,
            'last_message_at' => $conversation->last_message_at,
            'unread_count' => $this->unreadCount($conversation, $currentUser),
        ];
    }

    private function serializeMessage(ConversationMessage $message, User $currentUser): array
    {
        $sender = $message->sender;

        return [
            'id' => $message->id,
            'message' => $message->message,
            'created_at' => $message->created_at,
            'is_mine' => (int) $message->sender_id === (int) $currentUser->id,
            'sender' => [
                'id' => $sender?->id,
                'name' => $sender?->name ?? 'Utilisateur',
                'role' => $sender?->role,
                'avatar' => $this->initialFromName($sender?->name),
                'avatar_url' => $this->avatarUrl($sender?->avatar_path),
            ],
        ];
    }

    private function unreadCount(Conversation $conversation, User $user): int
    {
        $query = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('sender_id', '!=', $user->id);

        if ((int) $conversation->driver_id === (int) $user->id && $conversation->driver_last_read_at !== null) {
            $query->where('created_at', '>', $conversation->driver_last_read_at);
        }

        if ((int) $conversation->passenger_id === (int) $user->id && $conversation->passenger_last_read_at !== null) {
            $query->where('created_at', '>', $conversation->passenger_last_read_at);
        }

        return $query->count();
    }

    private function initialFromName(?string $name): string
    {
        if (! $name) {
            return 'U';
        }

        $trimmed = trim($name);

        return $trimmed !== '' ? strtoupper(substr($trimmed, 0, 1)) : 'U';
    }

    private function avatarUrl(?string $avatarPath): ?string
    {
        if (! $avatarPath) {
            return null;
        }

        return rtrim(request()->getSchemeAndHttpHost(), '/').'/storage/'.ltrim($avatarPath, '/');
    }


    private function broadcastConversationMessage(Conversation $conversation, ConversationMessage $message): void
    {
        $sender = $message->sender;

        try {
            Broadcast::private('conversation.'.$conversation->id)
                ->as('chat.message.sent')
                ->with([
                    'conversation_id' => $conversation->id,
                    'trip_id' => $conversation->trip_id,
                    'message' => [
                        'id' => $message->id,
                        'message' => $message->message,
                        'created_at' => $message->created_at,
                        'sender' => [
                            'id' => $sender?->id,
                            'name' => $sender?->name ?? 'Utilisateur',
                            'role' => $sender?->role,
                            'avatar' => $this->initialFromName($sender?->name),
                            'avatar_url' => $this->avatarUrl($sender?->avatar_path),
                        ],
                    ],
                ])
                ->toOthers()
                ->sendNow();
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function createUserNotification(?User $user, string $type, string $title, string $message, array $data = []): void
    {
        if (! $user) {
            return;
        }

        UserNotification::query()->create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data,
        ]);
    }
}








