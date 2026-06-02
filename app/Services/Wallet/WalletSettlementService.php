<?php

namespace App\Services\Wallet;

use App\Models\DriverDeposit;
use App\Models\DriverWithdrawal;
use App\Models\PassengerDeposit;
use App\Models\User;
use App\Models\UserNotification;
use App\Rules\BeninPhoneNumber;
use Illuminate\Support\Facades\DB;

class WalletSettlementService
{
    public function completeDriverDeposit(DriverDeposit $deposit): DriverDeposit
    {
        return DB::transaction(function () use ($deposit): DriverDeposit {
            /** @var DriverDeposit $lockedDeposit */
            $lockedDeposit = DriverDeposit::query()->lockForUpdate()->findOrFail($deposit->id);

            if ($lockedDeposit->status === 'completed') {
                return $lockedDeposit;
            }

            /** @var User|null $user */
            $user = User::query()->lockForUpdate()->find($lockedDeposit->user_id);

            if ($user) {
                $user->forceFill([
                    'driver_wallet_balance_fcfa' => max(0, (int) ($user->driver_wallet_balance_fcfa ?? 0) + (int) $lockedDeposit->amount_fcfa),
                ])->save();

                $this->createUserNotification(
                    $user,
                    'driver_deposit_completed',
                    'Recharge confirmee',
                    'Votre recharge de '.(int) $lockedDeposit->amount_fcfa.' FCFA est confirmee et disponible dans votre solde conducteur.',
                    [
                        'deposit_id' => $lockedDeposit->id,
                        'reference' => $lockedDeposit->reference,
                        'amount_fcfa' => (int) $lockedDeposit->amount_fcfa,
                        'network' => $lockedDeposit->network,
                        'phone' => $lockedDeposit->phone,
                    ]
                );
            }

            $lockedDeposit->forceFill([
                'status' => 'completed',
                'processed_at' => now(),
            ])->save();

            return $lockedDeposit;
        });
    }

    public function failDriverDeposit(DriverDeposit $deposit, ?string $reason = null): DriverDeposit
    {
        return DB::transaction(function () use ($deposit, $reason): DriverDeposit {
            /** @var DriverDeposit $lockedDeposit */
            $lockedDeposit = DriverDeposit::query()->lockForUpdate()->findOrFail($deposit->id);

            if ($lockedDeposit->status === 'completed') {
                return $lockedDeposit;
            }

            $statusWasFailed = $lockedDeposit->status === 'failed';
            $failureReason = $reason !== null && trim($reason) !== ''
                ? trim($reason)
                : $lockedDeposit->provider_failure_reason;

            $lockedDeposit->forceFill([
                'status' => 'failed',
                'processed_at' => now(),
                'provider_failure_reason' => $failureReason,
            ])->save();

            if (! $statusWasFailed) {
                /** @var User|null $user */
                $user = User::query()->find($lockedDeposit->user_id);

                $message = 'Votre recharge de '.(int) $lockedDeposit->amount_fcfa.' FCFA a echoue.';
                if ($failureReason) {
                    $message .= ' Motif: '.$failureReason;
                }

                $this->createUserNotification(
                    $user,
                    'driver_deposit_failed',
                    'Recharge echouee',
                    $message,
                    [
                        'deposit_id' => $lockedDeposit->id,
                        'reference' => $lockedDeposit->reference,
                        'amount_fcfa' => (int) $lockedDeposit->amount_fcfa,
                        'network' => $lockedDeposit->network,
                        'phone' => $lockedDeposit->phone,
                        'failure_reason' => $failureReason,
                    ]
                );
            }

            return $lockedDeposit;
        });
    }

    public function completePassengerDeposit(PassengerDeposit $deposit): PassengerDeposit
    {
        return DB::transaction(function () use ($deposit): PassengerDeposit {
            /** @var PassengerDeposit $lockedDeposit */
            $lockedDeposit = PassengerDeposit::query()->lockForUpdate()->findOrFail($deposit->id);

            if ($lockedDeposit->status === 'completed') {
                return $lockedDeposit;
            }

            /** @var User|null $user */
            $user = User::query()->lockForUpdate()->find($lockedDeposit->user_id);

            if ($user) {
                $user->forceFill([
                    'passenger_wallet_balance_fcfa' => max(0, (int) ($user->passenger_wallet_balance_fcfa ?? 0) + (int) $lockedDeposit->amount_fcfa),
                ])->save();

                $this->createUserNotification(
                    $user,
                    'passenger_deposit_completed',
                    'Depot confirme',
                    'Votre depot de '.(int) $lockedDeposit->amount_fcfa.' FCFA est confirme et disponible pour vos reservations.',
                    [
                        'deposit_id' => $lockedDeposit->id,
                        'reference' => $lockedDeposit->reference,
                        'amount_fcfa' => (int) $lockedDeposit->amount_fcfa,
                        'network' => $lockedDeposit->network,
                        'phone' => $lockedDeposit->phone,
                    ]
                );
            }

            $lockedDeposit->forceFill([
                'status' => 'completed',
                'processed_at' => now(),
            ])->save();

            return $lockedDeposit;
        });
    }

    public function failPassengerDeposit(PassengerDeposit $deposit, ?string $reason = null): PassengerDeposit
    {
        return DB::transaction(function () use ($deposit, $reason): PassengerDeposit {
            /** @var PassengerDeposit $lockedDeposit */
            $lockedDeposit = PassengerDeposit::query()->lockForUpdate()->findOrFail($deposit->id);

            if ($lockedDeposit->status === 'completed') {
                return $lockedDeposit;
            }

            $statusWasFailed = $lockedDeposit->status === 'failed';
            $failureReason = $reason !== null && trim($reason) !== ''
                ? trim($reason)
                : $lockedDeposit->provider_failure_reason;

            $lockedDeposit->forceFill([
                'status' => 'failed',
                'processed_at' => now(),
                'provider_failure_reason' => $failureReason,
            ])->save();

            if (! $statusWasFailed) {
                /** @var User|null $user */
                $user = User::query()->find($lockedDeposit->user_id);

                $message = 'Votre depot de '.(int) $lockedDeposit->amount_fcfa.' FCFA a echoue.';
                if ($failureReason) {
                    $message .= ' Motif: '.$failureReason;
                }

                $this->createUserNotification(
                    $user,
                    'passenger_deposit_failed',
                    'Depot echoue',
                    $message,
                    [
                        'deposit_id' => $lockedDeposit->id,
                        'reference' => $lockedDeposit->reference,
                        'amount_fcfa' => (int) $lockedDeposit->amount_fcfa,
                        'network' => $lockedDeposit->network,
                        'phone' => $lockedDeposit->phone,
                        'failure_reason' => $failureReason,
                    ]
                );
            }

            return $lockedDeposit;
        });
    }

    public function completeDriverWithdrawal(DriverWithdrawal $withdrawal): DriverWithdrawal
    {
        return DB::transaction(function () use ($withdrawal): DriverWithdrawal {
            /** @var DriverWithdrawal $lockedWithdrawal */
            $lockedWithdrawal = DriverWithdrawal::query()->lockForUpdate()->findOrFail($withdrawal->id);

            if ($lockedWithdrawal->status === 'completed') {
                return $lockedWithdrawal;
            }

            /** @var User|null $user */
            $user = User::query()->lockForUpdate()->find($lockedWithdrawal->user_id);

            if ($user) {
                $user->forceFill([
                    'driver_wallet_balance_fcfa' => max(0, (int) ($user->driver_wallet_balance_fcfa ?? 0) - (int) $lockedWithdrawal->amount_fcfa),
                ])->save();

                $networkLabel = BeninPhoneNumber::networkLabel((string) $lockedWithdrawal->network);

                $this->createUserNotification(
                    $user,
                    'driver_withdrawal_completed',
                    'Retrait effectue',
                    'Votre retrait de '.(int) $lockedWithdrawal->amount_fcfa.' FCFA vers '.$networkLabel.' est confirme.',
                    [
                        'withdrawal_id' => $lockedWithdrawal->id,
                        'reference' => $lockedWithdrawal->reference,
                        'amount_fcfa' => (int) $lockedWithdrawal->amount_fcfa,
                        'network' => $lockedWithdrawal->network,
                        'phone' => $lockedWithdrawal->phone,
                    ]
                );
            }

            $lockedWithdrawal->forceFill([
                'status' => 'completed',
                'processed_at' => now(),
            ])->save();

            return $lockedWithdrawal;
        });
    }

    public function failDriverWithdrawal(DriverWithdrawal $withdrawal, ?string $reason = null): DriverWithdrawal
    {
        return DB::transaction(function () use ($withdrawal, $reason): DriverWithdrawal {
            /** @var DriverWithdrawal $lockedWithdrawal */
            $lockedWithdrawal = DriverWithdrawal::query()->lockForUpdate()->findOrFail($withdrawal->id);

            if ($lockedWithdrawal->status === 'completed') {
                return $lockedWithdrawal;
            }

            $statusWasFailed = $lockedWithdrawal->status === 'failed';
            $failureReason = $reason !== null && trim($reason) !== ''
                ? trim($reason)
                : $lockedWithdrawal->provider_failure_reason;

            $lockedWithdrawal->forceFill([
                'status' => 'failed',
                'processed_at' => now(),
                'provider_failure_reason' => $failureReason,
            ])->save();

            if (! $statusWasFailed) {
                /** @var User|null $user */
                $user = User::query()->find($lockedWithdrawal->user_id);

                $message = 'Votre retrait de '.(int) $lockedWithdrawal->amount_fcfa.' FCFA a echoue.';
                if ($failureReason) {
                    $message .= ' Motif: '.$failureReason;
                }

                $this->createUserNotification(
                    $user,
                    'driver_withdrawal_failed',
                    'Retrait echoue',
                    $message,
                    [
                        'withdrawal_id' => $lockedWithdrawal->id,
                        'reference' => $lockedWithdrawal->reference,
                        'amount_fcfa' => (int) $lockedWithdrawal->amount_fcfa,
                        'network' => $lockedWithdrawal->network,
                        'phone' => $lockedWithdrawal->phone,
                        'failure_reason' => $failureReason,
                    ]
                );
            }

            return $lockedWithdrawal;
        });
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
