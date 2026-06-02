<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DriverSubscriptionController extends Controller
{
    /**
     * @var array<string, array<string, int|string>>
     */
    private const PLANS = [
        'weekly' => [
            'label' => 'Hebdomadaire',
            'price_fcfa' => 500,
            'duration_days' => 7,
        ],
        'monthly' => [
            'label' => 'Mensuel',
            'price_fcfa' => 1500,
            'duration_days' => 30,
        ],
    ];

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($response = $this->ensureDriver($user)) {
            return $response;
        }

        return response()->json([
            'subscription' => $this->subscriptionPayload($user),
            'plans' => $this->plansPayload(),
            'wallet' => $this->walletPayload($user),
        ]);
    }

    public function renew(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($response = $this->ensureDriver($user)) {
            return $response;
        }

        $validated = $request->validate([
            'plan' => ['required', 'in:weekly,monthly'],
        ]);

        $plan = (string) $validated['plan'];
        $planConfig = self::PLANS[$plan];
        $price = (int) $planConfig['price_fcfa'];
        $planLabel = (string) $planConfig['label'];

        /** @var User $refreshedUser */
        $refreshedUser = DB::transaction(function () use ($user, $plan, $planConfig, $price, $planLabel): User {
            /** @var User $lockedUser */
            $lockedUser = User::query()
                ->lockForUpdate()
                ->whereKey($user->id)
                ->firstOrFail();

            $currentBalance = (int) ($lockedUser->driver_wallet_balance_fcfa ?? 0);

            if ($currentBalance < $price) {
                throw ValidationException::withMessages([
                    'wallet' => ['Solde insuffisant. Rechargez votre compte conducteur pour renouveler l abonnement '.$planLabel.'.'],
                ]);
            }

            $settings = is_array($lockedUser->settings) ? $lockedUser->settings : [];
            $currentSubscription = is_array($settings['driver_subscription'] ?? null)
                ? $settings['driver_subscription']
                : [];

            $currentExpiresAt = $this->parseDateTime($currentSubscription['expires_at'] ?? null);
            $startAt = $currentExpiresAt && $currentExpiresAt->isFuture()
                ? $currentExpiresAt->copy()
                : now();
            $expiresAt = $startAt->copy()->addDays((int) $planConfig['duration_days']);

            $settings['driver_subscription'] = [
                'plan' => $plan,
                'status' => 'active',
                'started_at' => $startAt->toIso8601String(),
                'expires_at' => $expiresAt->toIso8601String(),
                'renewed_at' => now()->toIso8601String(),
            ];

            $lockedUser->forceFill([
                'settings' => $settings,
                'driver_wallet_balance_fcfa' => max(0, $currentBalance - $price),
            ])->save();

            $this->createUserNotification(
                $lockedUser,
                'driver_subscription_renewed',
                'Abonnement renouvele',
                'Votre abonnement '.$planLabel.' est actif jusqu au '.$expiresAt->format('d/m/Y').' ('.$price.' FCFA debites du solde conducteur).',
                [
                    'plan' => $plan,
                    'price_fcfa' => $price,
                    'expires_at' => $expiresAt->toIso8601String(),
                    'wallet_balance_fcfa' => (int) ($lockedUser->driver_wallet_balance_fcfa ?? 0),
                ]
            );

            return $lockedUser->fresh();
        });

        return response()->json([
            'message' => 'Abonnement '.$planLabel.' renouvele avec succes ('.$price.' FCFA debites du solde conducteur).',
            'subscription' => $this->subscriptionPayload($refreshedUser),
            'plans' => $this->plansPayload(),
            'wallet' => $this->walletPayload($refreshedUser),
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
    private function subscriptionPayload(User $user): array
    {
        $settings = is_array($user->settings) ? $user->settings : [];
        $subscription = is_array($settings['driver_subscription'] ?? null)
            ? $settings['driver_subscription']
            : [];

        $plan = (string) ($subscription['plan'] ?? '');
        if (! array_key_exists($plan, self::PLANS)) {
            $plan = '';
        }

        $startsAt = $this->parseDateTime($subscription['started_at'] ?? null);
        $expiresAt = $this->parseDateTime($subscription['expires_at'] ?? null);

        $status = 'none';
        if ($plan !== '' && $expiresAt) {
            $status = $expiresAt->isFuture() ? 'active' : 'expired';
        }

        $remainingDays = $status === 'active' && $expiresAt
            ? (int) now()->diffInDays($expiresAt)
            : 0;

        return [
            'plan' => $plan !== '' ? $plan : null,
            'label' => $plan !== '' ? (string) self::PLANS[$plan]['label'] : null,
            'price_fcfa' => $plan !== '' ? (int) self::PLANS[$plan]['price_fcfa'] : null,
            'duration_days' => $plan !== '' ? (int) self::PLANS[$plan]['duration_days'] : null,
            'status' => $status,
            'is_active' => $status === 'active',
            'starts_at' => $startsAt?->toIso8601String(),
            'expires_at' => $expiresAt?->toIso8601String(),
            'remaining_days' => $remainingDays,
        ];
    }

    /**
     * @return array<int, array<string, int|string>>
     */
    private function plansPayload(): array
    {
        return collect(self::PLANS)
            ->map(fn (array $plan, string $id): array => [
                'id' => $id,
                'label' => (string) $plan['label'],
                'price_fcfa' => (int) $plan['price_fcfa'],
                'duration_days' => (int) $plan['duration_days'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function walletPayload(User $user): array
    {
        $availableBalance = (int) ($user->driver_wallet_balance_fcfa ?? 0);

        return [
            'available_balance_fcfa' => $availableBalance,
            'can_pay_weekly' => $availableBalance >= (int) self::PLANS['weekly']['price_fcfa'],
            'can_pay_monthly' => $availableBalance >= (int) self::PLANS['monthly']['price_fcfa'],
        ];
    }

    private function parseDateTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
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
