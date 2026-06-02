<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\DriverDeposit;
use App\Models\DriverWithdrawal;
use App\Models\User;
use App\Models\UserNotification;
use App\Rules\BeninPhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DriverWalletController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($response = $this->ensureDriver($user)) {
            return $response;
        }

        return response()->json([
            'wallet' => $this->walletPayload($user),
        ]);
    }

    public function withdrawals(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($response = $this->ensureDriver($user)) {
            return $response;
        }

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $limit = (int) ($validated['limit'] ?? 20);

        $withdrawals = DriverWithdrawal::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $withdrawals
                ->map(fn (DriverWithdrawal $withdrawal): array => $this->serializeWithdrawal($withdrawal))
                ->all(),
            'meta' => [
                'total' => $withdrawals->count(),
            ],
        ]);
    }

    public function deposits(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($response = $this->ensureDriver($user)) {
            return $response;
        }

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $limit = (int) ($validated['limit'] ?? 20);

        $deposits = DriverDeposit::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $deposits
                ->map(fn (DriverDeposit $deposit): array => $this->serializeDeposit($deposit))
                ->all(),
            'meta' => [
                'total' => $deposits->count(),
            ],
        ]);
    }

    public function withdraw(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($response = $this->ensureDriver($user)) {
            return $response;
        }

        $validated = $request->validate([
            'amount_fcfa' => ['required', 'integer', 'min:500'],
            'current_password' => ['required', 'string'],
        ]);

        if (! Hash::check((string) $validated['current_password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Le mot de passe est incorrect.'],
            ]);
        }

        $amount = (int) $validated['amount_fcfa'];

        $withdrawal = DB::transaction(function () use ($user, $amount): DriverWithdrawal {
            /** @var User $lockedUser */
            $lockedUser = User::query()
                ->lockForUpdate()
                ->whereKey($user->id)
                ->firstOrFail();

            $network = $this->detectNetworkFromPhone((string) $lockedUser->phone);

            if (! $network) {
                throw ValidationException::withMessages([
                    'phone' => ['Le numero de profil ne correspond a aucun reseau mobile money pris en charge au Benin. Mettez ce numero a jour dans votre profil.'],
                ]);
            }

            $currentBalance = (int) ($lockedUser->driver_wallet_balance_fcfa ?? 0);

            if ($currentBalance < $amount) {
                $pendingBalance = $this->pendingPayoutAmount($lockedUser);
                $message = 'Solde disponible insuffisant pour ce retrait.';

                if ($pendingBalance > 0) {
                    $message .= ' '.$pendingBalance.' FCFA sont en attente de confirmation passager et ne sont pas encore retirables.';
                }

                throw ValidationException::withMessages([
                    'amount_fcfa' => [$message],
                ]);
            }

            $withdrawal = DriverWithdrawal::query()->create([
                'user_id' => $lockedUser->id,
                'amount_fcfa' => $amount,
                'network' => $network,
                'phone' => (string) $lockedUser->phone,
                'status' => 'completed',
                'reference' => $this->withdrawalReference(),
                'requested_at' => now(),
                'processed_at' => now(),
            ]);

            $lockedUser->forceFill([
                'driver_wallet_balance_fcfa' => max(0, $currentBalance - $amount),
            ])->save();

            $this->createUserNotification(
                $lockedUser,
                'driver_withdrawal_completed',
                'Retrait effectue',
                'Votre retrait de '.$amount.' FCFA vers '.$this->networkLabel($network).' est confirme.',
                [
                    'withdrawal_id' => $withdrawal->id,
                    'reference' => $withdrawal->reference,
                    'amount_fcfa' => $amount,
                    'network' => $network,
                    'phone' => (string) $lockedUser->phone,
                ]
            );

            return $withdrawal;
        });

        return response()->json([
            'message' => 'Retrait valide et transfert securise vers votre numero de profil.',
            'wallet' => $this->walletPayload($user->fresh()),
            'withdrawal' => $this->serializeWithdrawal($withdrawal),
        ]);
    }

    public function deposit(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($response = $this->ensureDriver($user)) {
            return $response;
        }

        $validated = $request->validate([
            'amount_fcfa' => ['required', 'integer', 'min:500'],
            'current_password' => ['required', 'string'],
        ]);

        $amount = (int) $validated['amount_fcfa'];

        $deposit = DB::transaction(function () use ($user, $amount, $validated): DriverDeposit {
            /** @var User $lockedUser */
            $lockedUser = User::query()
                ->lockForUpdate()
                ->whereKey($user->id)
                ->firstOrFail();

            if (! Hash::check((string) $validated['current_password'], (string) $lockedUser->password)) {
                throw ValidationException::withMessages([
                    'current_password' => ['Le mot de passe est incorrect.'],
                ]);
            }

            $network = $this->detectNetworkFromPhone((string) $lockedUser->phone);

            if (! $network) {
                throw ValidationException::withMessages([
                    'phone' => ['Le numero de profil ne correspond a aucun reseau mobile money pris en charge au Benin. Mettez ce numero a jour dans votre profil.'],
                ]);
            }

            $deposit = DriverDeposit::query()->create([
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
                'driver_wallet_balance_fcfa' => max(0, (int) ($lockedUser->driver_wallet_balance_fcfa ?? 0) + $amount),
            ])->save();

            $this->createUserNotification(
                $lockedUser,
                'driver_deposit_completed',
                'Recharge confirmee',
                'Votre recharge de '.$amount.' FCFA est confirmee et disponible dans votre solde conducteur.',
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
            'message' => 'Recharge confirmee et solde conducteur credite avec succes.',
            'wallet' => $this->walletPayload($user->fresh()),
            'deposit' => $this->serializeDeposit($deposit),
        ]);
    }

    private function ensureDriver(User $user): ?JsonResponse
    {
        if ($user->role === 'driver') {
            return null;
        }

        return response()->json([
            'message' => 'Acces reserve aux conducteurs.',
        ], 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function walletPayload(User $user): array
    {
        $available = (int) ($user->driver_wallet_balance_fcfa ?? 0);
        $pending = $this->pendingPayoutAmount($user);
        $detectedNetwork = $this->detectNetworkFromPhone((string) $user->phone);

        return [
            'available_balance_fcfa' => $available,
            'pending_balance_fcfa' => $pending,
            'total_balance_fcfa' => $available + $pending,
            'commission_rate_percent' => 5,
            'withdrawal_phone' => $user->phone,
            'withdrawal_network' => $detectedNetwork,
            'withdrawal_network_label' => $detectedNetwork ? $this->networkLabel($detectedNetwork) : null,
            'withdrawal_network_supported' => $detectedNetwork !== null,
            'minimum_withdrawal_fcfa' => 500,
            'recharge_phone' => $user->phone,
            'recharge_network' => $detectedNetwork,
            'recharge_network_label' => $detectedNetwork ? $this->networkLabel($detectedNetwork) : null,
            'recharge_network_supported' => $detectedNetwork !== null,
            'minimum_deposit_fcfa' => 500,
        ];
    }

    private function pendingPayoutAmount(User $user): int
    {
        $sum = Booking::query()
            ->join('trips', 'trips.id', '=', 'bookings.trip_id')
            ->where('trips.driver_id', $user->id)
            ->where('bookings.payout_status', 'pending_confirmation')
            ->sum('bookings.payout_amount_fcfa');

        return (int) $sum;
    }

    private function networkLabel(string $network): string
    {
        return BeninPhoneNumber::networkLabel($network);
    }

    private function detectNetworkFromPhone(string $phone): ?string
    {
        return BeninPhoneNumber::detectNetwork($phone, true);
    }

    private function withdrawalReference(): string
    {
        return 'WD-'.strtoupper((string) Str::ulid());
    }

    private function depositReference(): string
    {
        return 'DD-'.strtoupper((string) Str::ulid());
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeWithdrawal(DriverWithdrawal $withdrawal): array
    {
        return [
            'id' => $withdrawal->id,
            'amount_fcfa' => (int) $withdrawal->amount_fcfa,
            'network' => $withdrawal->network,
            'network_label' => $this->networkLabel((string) $withdrawal->network),
            'phone' => $withdrawal->phone,
            'status' => $withdrawal->status,
            'reference' => $withdrawal->reference,
            'requested_at' => $withdrawal->requested_at,
            'processed_at' => $withdrawal->processed_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDeposit(DriverDeposit $deposit): array
    {
        return [
            'id' => $deposit->id,
            'amount_fcfa' => (int) $deposit->amount_fcfa,
            'network' => $deposit->network,
            'network_label' => $this->networkLabel((string) $deposit->network),
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
