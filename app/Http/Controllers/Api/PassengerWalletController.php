<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PassengerDeposit;
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

class PassengerWalletController extends Controller
{
    public function __construct(
        private readonly FedaPayGateway $fedaPayGateway,
        private readonly WalletSettlementService $walletSettlementService,
    ) {}

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
                'status' => 'processing',
                'provider' => $this->fedaPayGateway->isEnabled() ? 'fedapay' : 'internal',
                'reference' => $this->depositReference(),
                'requested_at' => now(),
            ]);

            $this->createUserNotification(
                $lockedUser,
                'passenger_deposit_processing',
                'Depot en cours',
                'Votre demande de depot de '.$amount.' FCFA est en cours de traitement.',
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
            $deposit = $this->walletSettlementService->completePassengerDeposit($deposit);

            return response()->json([
                'message' => 'Depot confirme et solde credite avec succes.',
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
                'passenger_deposit',
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
            $message = 'Demande de depot transmise. Validez le paiement sur votre telephone.';

            if ($localStatus === 'completed') {
                $deposit = $this->walletSettlementService->completePassengerDeposit($deposit);
                $message = 'Depot confirme par FeDaPay et solde credite.';
            } elseif ($localStatus === 'failed') {
                $deposit = $this->walletSettlementService->failPassengerDeposit(
                    $deposit,
                    is_string($providerResult['provider_status'] ?? null) ? (string) $providerResult['provider_status'] : null,
                );
                $message = 'Demande de depot refusee par FeDaPay.';
            }

            return response()->json([
                'message' => $message,
                'wallet' => $this->walletPayload($user->fresh()),
                'deposit' => $this->serializeDeposit($deposit->fresh()),
            ]);
        } catch (RuntimeException $exception) {
            $deposit = $this->walletSettlementService->failPassengerDeposit($deposit, $exception->getMessage());

            return response()->json([
                'message' => 'La demande de depot a echoue: '.$exception->getMessage(),
                'wallet' => $this->walletPayload($user->fresh()),
                'deposit' => $this->serializeDeposit($deposit->fresh()),
            ], 502);
        } catch (Throwable) {
            $deposit = $this->walletSettlementService->failPassengerDeposit($deposit, 'Erreur technique FeDaPay.');

            return response()->json([
                'message' => 'La demande de depot a echoue suite a une erreur technique FeDaPay.',
                'wallet' => $this->walletPayload($user->fresh()),
                'deposit' => $this->serializeDeposit($deposit->fresh()),
            ], 502);
        }
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
            'provider' => $this->fedaPayGateway->isEnabled() ? 'fedapay' : 'internal',
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
