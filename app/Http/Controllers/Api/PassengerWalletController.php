<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PassengerDeposit;
use App\Models\User;
use App\Models\UserNotification;
use App\Rules\BeninPhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PassengerWalletController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($response = $this->ensurePassenger($user)) {
            return $response;
        }

        return response()->json([
            'wallet' => $this->walletPayload($user),
        ]);
    }

    public function deposits(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($response = $this->ensurePassenger($user)) {
            return $response;
        }

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $limit = (int) ($validated['limit'] ?? 20);

        $deposits = PassengerDeposit::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $deposits
                ->map(fn (PassengerDeposit $deposit): array => $this->serializeDeposit($deposit))
                ->all(),
            'meta' => [
                'total' => $deposits->count(),
            ],
        ]);
    }

    public function deposit(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($response = $this->ensurePassenger($user)) {
            return $response;
        }

        $validated = $request->validate([
            'amount_fcfa' => ['required', 'integer', 'min:500'],
            'current_password' => ['required', 'string'],
        ]);

        $amount = (int) $validated['amount_fcfa'];
        $submittedPassword = (string) $validated['current_password'];

        $deposit = DB::transaction(function () use ($user, $amount, $submittedPassword): PassengerDeposit {
            /** @var User $lockedUser */
            $lockedUser = User::query()
                ->lockForUpdate()
                ->whereKey($user->id)
                ->firstOrFail();

            if (! Hash::check($submittedPassword, (string) $lockedUser->password)) {
                throw ValidationException::withMessages([
                    'current_password' => ['Le mot de passe est incorrect.'],
                ]);
            }

            $network = BeninPhoneNumber::detectNetwork((string) $lockedUser->phone, true);

            if (! $network) {
                throw ValidationException::withMessages([
                    'phone' => ['Le numero de profil ne correspond a aucun reseau mobile money pris en charge au Benin. Mettez ce numero a jour dans votre profil.'],
                ]);
            }

            $deposit = PassengerDeposit::query()->create([
                'user_id' => $lockedUser->id,
                'amount_fcfa' => $amount,
                'network' => $network,
                'phone' => (string) $lockedUser->phone,
                'status' => 'completed',
                'reference' => $this->depositReference(),
                'requested_at' => now(),
                'processed_at' => now(),
            ]);

            $lockedUser->forceFill([
                'passenger_wallet_balance_fcfa' => max(0, (int) ($lockedUser->passenger_wallet_balance_fcfa ?? 0) + $amount),
            ])->save();

            $this->createUserNotification(
                $lockedUser,
                'passenger_deposit_completed',
                'Depot confirme',
                'Votre depot de '.$amount.' FCFA est confirme et disponible pour vos reservations.',
                [
                    'deposit_id' => $deposit->id,
                    'reference' => $deposit->reference,
                    'amount_fcfa' => $amount,
                    'network' => $network,
                    'phone' => (string) $lockedUser->phone,
                ]
            );

            return $deposit;
        });

        return response()->json([
            'message' => 'Depot confirme et solde credite avec succes.',
            'wallet' => $this->walletPayload($user->fresh()),
            'deposit' => $this->serializeDeposit($deposit),
        ]);
    }

    private function ensurePassenger(User $user): ?JsonResponse
    {
        if ($user->role === 'passenger') {
            return null;
        }

        return response()->json([
            'message' => 'Acces reserve aux passagers.',
        ], 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function walletPayload(User $user): array
    {
        $available = (int) ($user->passenger_wallet_balance_fcfa ?? 0);
        $network = BeninPhoneNumber::detectNetwork((string) $user->phone, true);

        return [
            'available_balance_fcfa' => $available,
            'pending_balance_fcfa' => 0,
            'total_balance_fcfa' => $available,
            'minimum_deposit_fcfa' => 500,
            'recharge_phone' => $user->phone,
            'recharge_network' => $network,
            'recharge_network_label' => $network ? BeninPhoneNumber::networkLabel($network) : null,
            'recharge_network_supported' => $network !== null,
            'can_book_when_balance_sufficient' => true,
        ];
    }

    private function depositReference(): string
    {
        return 'DP-'.strtoupper((string) Str::ulid());
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDeposit(PassengerDeposit $deposit): array
    {
        return [
            'id' => $deposit->id,
            'amount_fcfa' => (int) $deposit->amount_fcfa,
            'network' => $deposit->network,
            'network_label' => BeninPhoneNumber::networkLabel((string) $deposit->network),
            'phone' => $deposit->phone,
            'status' => $deposit->status,
            'reference' => $deposit->reference,
            'requested_at' => $deposit->requested_at,
            'processed_at' => $deposit->processed_at,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
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

