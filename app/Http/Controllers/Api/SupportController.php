<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $limit = (int) ($validated['limit'] ?? 10);

        $tickets = SupportTicket::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $tickets->map(fn (SupportTicket $ticket): array => $this->serializeTicket($ticket))->all(),
            'meta' => [
                'total' => $tickets->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'category' => ['required', 'in:general,payment,account,safety'],
            'message' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $ticket = SupportTicket::query()->create([
            'user_id' => $user->id,
            'role' => $user->role,
            'category' => $validated['category'],
            'message' => trim((string) $validated['message']),
            'status' => 'open',
        ]);

        return response()->json([
            'message' => 'Message envoye. Un agent vous repond sous 24h.',
            'ticket' => $this->serializeTicket($ticket),
        ], 201);
    }

    private function serializeTicket(SupportTicket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'category' => $ticket->category,
            'message' => $ticket->message,
            'status' => $ticket->status,
            'admin_note' => $ticket->admin_note,
            'resolved_at' => $ticket->resolved_at,
            'created_at' => $ticket->created_at,
        ];
    }
}