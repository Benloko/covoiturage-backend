<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\DriverDeposit;
use App\Models\DriverWithdrawal;
use App\Models\User;
use App\Models\UserNotification;
use App\Rules\BeninPhoneNumber;
use App\Services\FedaPay\FedaPayGateway;
use App\Services\Wallet\WalletSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class DriverWalletController extends Controller
{
    public function __construct(
        private readonly FedaPayGateway $fedaPayGateway,
        private readonly WalletSettlementService $walletSettlementService,
    ) {}

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
            $reservedProcessing = $this->processingWithdrawalsAmount($lockedUser);
            $withdrawableBalance = max(0, $currentBalance - $reservedProcessing);

            if ($withdrawableBalance < $amount) {
                $pendingBalance = $this->pendingPayoutAmount($lockedUser);
                $message = 'Solde disponible insuffisant pour ce retrait.';

                if ($reservedProcessing > 0) {
                    $message .= ' '.$reservedProcessing.' FCFA sont deja en cours de retrait et temporairement reserves.';
                }

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
                'status' => 'processing',
                'provider' => $this->fedaPayGateway->isEnabled() ? 'fedapay' : 'internal',
                'reference' => $this->withdrawalReference(),
                'requested_at' => now(),
            ]);

            $this->createUserNotification(
                $lockedUser,
                'driver_withdrawal_processing',
                'Retrait en cours',
                'Votre demande de retrait de '.$amount.' FCFA est en cours de traitement.',
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

        if (! $this->fedaPayGateway->isEnabled()) {
            $withdrawal = $this->walletSettlementService->completeDriverWithdrawal($withdrawal);

            return response()->json([
                'message' => 'Retrait valide et transfert securise vers votre numero de profil.',
                'wallet' => $this->walletPayload($user->fresh()),
                'withdrawal' => $this->serializeWithdrawal($withdrawal),
            ]);
        }

        try {
            $providerResult = $this->fedaPayGateway->initiateWithdrawal(
                $user->fresh() ?? $user,
                (string) $withdrawal->network,
                (string) $withdrawal->phone,
                (int) $withdrawal->amount_fcfa,
                (string) $withdrawal->reference,
                'driver_withdrawal',
                (int) $withdrawal->id,
            );

            $withdrawal->forceFill([
                'provider' => (string) ($providerResult['provider'] ?? 'fedapay'),
                'provider_transaction_id' => $providerResult['provider_transaction_id'] ?? null,
                'provider_reference' => $providerResult['provider_reference'] ?? null,
                'provider_status' => $providerResult['provider_status'] ?? null,
                'provider_payload' => $providerResult['provider_payload'] ?? null,
            ])->save();

            $localStatus = (string) ($providerResult['local_status'] ?? 'processing');
            $message = 'Demande de retrait transmise. Validation en cours sur FeDaPay.';

            if ($localStatus === 'completed') {
                $withdrawal = $this->walletSettlementService->completeDriverWithdrawal($withdrawal);
                $message = 'Retrait confirme par FeDaPay et transfert effectue.';
            } elseif ($localStatus === 'failed') {
                $withdrawal = $this->walletSettlementService->failDriverWithdrawal(
                    $withdrawal,
                    is_string($providerResult['provider_status'] ?? null) ? (string) $providerResult['provider_status'] : null,
                );
                $message = 'Demande de retrait refusee par FeDaPay.';
            }

            return response()->json([
                'message' => $message,
                'wallet' => $this->walletPayload($user->fresh()),
                'withdrawal' => $this->serializeWithdrawal($withdrawal->fresh()),
            ]);
        } catch (RuntimeException $exception) {
            $withdrawal = $this->walletSettlementService->failDriverWithdrawal($withdrawal, $exception->getMessage());

            return response()->json([
                'message' => 'La demande de retrait a echoue: '.$exception->getMessage(),
                'wallet' => $this->walletPayload($user->fresh()),
                'withdrawal' => $this->serializeWithdrawal($withdrawal->fresh()),
            ], 502);
        } catch (Throwable) {
            $withdrawal = $this->walletSettlementService->failDriverWithdrawal($withdrawal, 'Erreur technique FeDaPay.');

            return response()->json([
                'message' => 'La demande de retrait a echoue suite a une erreur technique FeDaPay.',
                'wallet' => $this->walletPayload($user->fresh()),
                'withdrawal' => $this->serializeWithdrawal($withdrawal->fresh()),
            ], 502);
        }
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
                'status' => 'processing',
                'provider' => $this->fedaPayGateway->isEnabled() ? 'fedapay' : 'internal',
                'reference' => $this->depositReference(),
                'requested_at' => now(),
            ]);

            $this->createUserNotification(
                $lockedUser,
                'driver_deposit_processing',
                'Recharge en cours',
                'Votre demande de recharge de '.$amount.' FCFA est en cours de traitement.',
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

        if (! $this->fedaPayGateway->isEnabled()) {
            $deposit = $this->walletSettlementService->completeDriverDeposit($deposit);

            return response()->json([
                'message' => 'Recharge confirmee et solde conducteur credite avec succes.',
                'wallet' => $this->walletPayload($user->fresh()),
                'deposit' => $this->serializeDeposit($deposit),
            ]);
        }

        try {
            $providerResult = $this->fedaPayGateway->initiateDeposit(
                $user->fresh() ?? $user,
                (string) $deposit->network,
                (string) $deposit->phone,
                (int) $deposit->amount_fcfa,
                (string) $deposit->reference,
                'driver_deposit',
                (int) $deposit->id,
            );

            $deposit->forceFill([
                'provider' => (string) ($providerResult['provider'] ?? 'fedapay'),
                'provider_transaction_id' => $providerResult['provider_transaction_id'] ?? null,
                'provider_reference' => $providerResult['provider_reference'] ?? null,
                'provider_status' => $providerResult['provider_status'] ?? null,
                'provider_payload' => $providerResult['provider_payload'] ?? null,
            ])->save();

            $localStatus = (string) ($providerResult['local_status'] ?? 'processing');
            $message = 'Demande de recharge transmise. Validez le paiement sur votre telephone.';

            if ($localStatus === 'completed') {
                $deposit = $this->walletSettlementService->completeDriverDeposit($deposit);
                $message = 'Recharge confirmee par FeDaPay et solde credite.';
            } elseif ($localStatus === 'failed') {
                $deposit = $this->walletSettlementService->failDriverDeposit(
                    $deposit,
                    is_string($providerResult['provider_status'] ?? null) ? (string) $providerResult['provider_status'] : null,
                );
                $message = 'Demande de recharge refusee par FeDaPay.';
            }

            return response()->json([
                'message' => $message,
                'wallet' => $this->walletPayload($user->fresh()),
                'deposit' => $this->serializeDeposit($deposit->fresh()),
            ]);
        } catch (RuntimeException $exception) {
            $deposit = $this->walletSettlementService->failDriverDeposit($deposit, $exception->getMessage());

            return response()->json([
                'message' => 'La demande de recharge a echoue: '.$exception->getMessage(),
                'wallet' => $this->walletPayload($user->fresh()),
                'deposit' => $this->serializeDeposit($deposit->fresh()),
            ], 502);
        } catch (Throwable) {
            $deposit = $this->walletSettlementService->failDriverDeposit($deposit, 'Erreur technique FeDaPay.');

            return response()->json([
                'message' => 'La demande de recharge a echoue suite a une erreur technique FeDaPay.',
                'wallet' => $this->walletPayload($user->fresh()),
                'deposit' => $this->serializeDeposit($deposit->fresh()),
            ], 502);
        }
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
        $baseBalance = (int) ($user->driver_wallet_balance_fcfa ?? 0);
        $processingWithdrawals = $this->processingWithdrawalsAmount($user);
        $available = max(0, $baseBalance - $processingWithdrawals);
        $pending = $this->pendingPayoutAmount($user);
        $detectedNetwork = $this->detectNetworkFromPhone((string) $user->phone);

        return [
            'available_balance_fcfa' => $available,
            'pending_balance_fcfa' => $pending,
            'processing_withdrawals_fcfa' => $processingWithdrawals,
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
            'provider' => $this->fedaPayGateway->isEnabled() ? 'fedapay' : 'internal',
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

    private function processingWithdrawalsAmount(User $user): int
    {
        return (int) DriverWithdrawal::query()
            ->where('user_id', $user->id)
            ->where('status', 'processing')
            ->sum('amount_fcfa');
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
            'provider_status' => $withdrawal->provider_status,
            'failure_reason' => $withdrawal->provider_failure_reason,
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
            'provider_status' => $deposit->provider_status,
            'failure_reason' => $deposit->provider_failure_reason,
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
