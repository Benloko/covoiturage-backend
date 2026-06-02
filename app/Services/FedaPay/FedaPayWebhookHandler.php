<?php

namespace App\Services\FedaPay;

use App\Models\DriverDeposit;
use App\Models\DriverWithdrawal;
use App\Models\PassengerDeposit;
use App\Services\Wallet\WalletSettlementService;
use Illuminate\Support\Str;

class FedaPayWebhookHandler
{
    public function __construct(
        private readonly FedaPayGateway $fedaPayGateway,
        private readonly WalletSettlementService $walletSettlementService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        $eventName = Str::lower(trim((string) ($payload['name'] ?? data_get($payload, 'event.name') ?? $payload['type'] ?? '')));
        $entity = $this->extractProviderEntity($payload);

        $metadata = $this->extractOperationMetadata($payload, $entity);

        $providerStatus = $this->firstString([
            data_get($entity, 'status'),
            data_get($payload, 'status'),
            data_get($payload, 'resolved_object.status'),
        ]);

        $localStatus = $this->fedaPayGateway->resolveLocalStatus($providerStatus, $eventName);

        $providerTransactionId = $this->firstString([
            data_get($entity, 'id'),
            data_get($payload, 'object_id'),
            data_get($payload, 'resolved_object.id'),
        ]);

        $providerReference = $this->firstString([
            data_get($entity, 'reference'),
            data_get($payload, 'resolved_object.reference'),
        ]);

        $reference = $this->firstString([
            data_get($metadata, 'app_reference'),
            $providerReference,
        ]);

        $operation = Str::lower(trim((string) data_get($metadata, 'operation', '')));
        $operationId = $this->toNullableInt(data_get($metadata, 'operation_id'));

        $failureReason = $this->firstString([
            data_get($entity, 'last_error_message'),
            data_get($entity, 'last_error_code'),
            data_get($payload, 'message'),
            data_get($payload, 'error.message'),
            data_get($payload, 'error'),
        ]);

        if ($operation === 'driver_deposit') {
            $record = $this->findDriverDeposit($operationId, $reference, $providerReference, $providerTransactionId);

            if ($record) {
                $this->applyDriverDepositWebhook($record, $payload, $providerStatus, $providerTransactionId, $providerReference, $localStatus, $failureReason);
            }

            return;
        }

        if ($operation === 'passenger_deposit') {
            $record = $this->findPassengerDeposit($operationId, $reference, $providerReference, $providerTransactionId);

            if ($record) {
                $this->applyPassengerDepositWebhook($record, $payload, $providerStatus, $providerTransactionId, $providerReference, $localStatus, $failureReason);
            }

            return;
        }

        if ($operation === 'driver_withdrawal') {
            $record = $this->findDriverWithdrawal($operationId, $reference, $providerReference, $providerTransactionId);

            if ($record) {
                $this->applyDriverWithdrawalWebhook($record, $payload, $providerStatus, $providerTransactionId, $providerReference, $localStatus, $failureReason);
            }

            return;
        }

        if (str_starts_with($eventName, 'transaction.')) {
            $driverDeposit = $this->findDriverDeposit($operationId, $reference, $providerReference, $providerTransactionId);

            if ($driverDeposit) {
                $this->applyDriverDepositWebhook($driverDeposit, $payload, $providerStatus, $providerTransactionId, $providerReference, $localStatus, $failureReason);

                return;
            }

            $passengerDeposit = $this->findPassengerDeposit($operationId, $reference, $providerReference, $providerTransactionId);

            if ($passengerDeposit) {
                $this->applyPassengerDepositWebhook($passengerDeposit, $payload, $providerStatus, $providerTransactionId, $providerReference, $localStatus, $failureReason);
            }

            return;
        }

        if (str_starts_with($eventName, 'payout.')) {
            $driverWithdrawal = $this->findDriverWithdrawal($operationId, $reference, $providerReference, $providerTransactionId);

            if ($driverWithdrawal) {
                $this->applyDriverWithdrawalWebhook($driverWithdrawal, $payload, $providerStatus, $providerTransactionId, $providerReference, $localStatus, $failureReason);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyDriverDepositWebhook(
        DriverDeposit $deposit,
        array $payload,
        ?string $providerStatus,
        ?string $providerTransactionId,
        ?string $providerReference,
        string $localStatus,
        ?string $failureReason,
    ): void {
        $deposit->forceFill([
            'provider' => 'fedapay',
            'provider_transaction_id' => $providerTransactionId ?: $deposit->provider_transaction_id,
            'provider_reference' => $providerReference ?: $deposit->provider_reference,
            'provider_status' => $providerStatus ?: $deposit->provider_status,
            'provider_payload' => $payload,
            'provider_last_webhook_at' => now(),
        ])->save();

        if ($localStatus === 'completed') {
            $this->walletSettlementService->completeDriverDeposit($deposit);

            return;
        }

        if ($localStatus === 'failed') {
            $this->walletSettlementService->failDriverDeposit($deposit, $failureReason);

            return;
        }

        if (! in_array($deposit->status, ['completed', 'failed'], true)) {
            $deposit->forceFill([
                'status' => 'processing',
            ])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyPassengerDepositWebhook(
        PassengerDeposit $deposit,
        array $payload,
        ?string $providerStatus,
        ?string $providerTransactionId,
        ?string $providerReference,
        string $localStatus,
        ?string $failureReason,
    ): void {
        $deposit->forceFill([
            'provider' => 'fedapay',
            'provider_transaction_id' => $providerTransactionId ?: $deposit->provider_transaction_id,
            'provider_reference' => $providerReference ?: $deposit->provider_reference,
            'provider_status' => $providerStatus ?: $deposit->provider_status,
            'provider_payload' => $payload,
            'provider_last_webhook_at' => now(),
        ])->save();

        if ($localStatus === 'completed') {
            $this->walletSettlementService->completePassengerDeposit($deposit);

            return;
        }

        if ($localStatus === 'failed') {
            $this->walletSettlementService->failPassengerDeposit($deposit, $failureReason);

            return;
        }

        if (! in_array($deposit->status, ['completed', 'failed'], true)) {
            $deposit->forceFill([
                'status' => 'processing',
            ])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyDriverWithdrawalWebhook(
        DriverWithdrawal $withdrawal,
        array $payload,
        ?string $providerStatus,
        ?string $providerTransactionId,
        ?string $providerReference,
        string $localStatus,
        ?string $failureReason,
    ): void {
        $withdrawal->forceFill([
            'provider' => 'fedapay',
            'provider_transaction_id' => $providerTransactionId ?: $withdrawal->provider_transaction_id,
            'provider_reference' => $providerReference ?: $withdrawal->provider_reference,
            'provider_status' => $providerStatus ?: $withdrawal->provider_status,
            'provider_payload' => $payload,
            'provider_last_webhook_at' => now(),
        ])->save();

        if ($localStatus === 'completed') {
            $this->walletSettlementService->completeDriverWithdrawal($withdrawal);

            return;
        }

        if ($localStatus === 'failed') {
            $this->walletSettlementService->failDriverWithdrawal($withdrawal, $failureReason);

            return;
        }

        if (! in_array($withdrawal->status, ['completed', 'failed'], true)) {
            $withdrawal->forceFill([
                'status' => 'processing',
            ])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $entity
     * @return array<string, mixed>
     */
    private function extractOperationMetadata(array $payload, array $entity): array
    {
        foreach ([$entity, $payload] as $source) {
            foreach (['custom_metadata', 'metadata'] as $key) {
                $value = data_get($source, $key);

                if (is_array($value) && (isset($value['operation']) || isset($value['app_reference']) || isset($value['operation_id']))) {
                    return $value;
                }
            }
        }

        $queue = [$payload];

        while ($queue !== []) {
            $current = array_shift($queue);

            if (! is_array($current)) {
                continue;
            }

            foreach (['custom_metadata', 'metadata'] as $key) {
                $value = data_get($current, $key);

                if (is_array($value) && (isset($value['operation']) || isset($value['app_reference']) || isset($value['operation_id']))) {
                    return $value;
                }
            }

            foreach ($current as $value) {
                if (is_array($value)) {
                    $queue[] = $value;
                }
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function extractProviderEntity(array $payload): array
    {
        $resolved = data_get($payload, 'resolved_object');
        if (is_array($resolved)) {
            return $resolved;
        }

        foreach (['object', 'entity', 'data', 'transaction', 'payout'] as $candidatePath) {
            $candidate = data_get($payload, $candidatePath);

            if (is_array($candidate) && (array_key_exists('id', $candidate) || array_key_exists('reference', $candidate))) {
                return $candidate;
            }
        }

        $queue = [$payload];

        while ($queue !== []) {
            $current = array_shift($queue);

            if (! is_array($current)) {
                continue;
            }

            $hasId = array_key_exists('id', $current);
            $hasReference = array_key_exists('reference', $current);
            $hasStatus = array_key_exists('status', $current);

            if ($hasId && ($hasReference || $hasStatus)) {
                return $current;
            }

            foreach ($current as $value) {
                if (is_array($value)) {
                    $queue[] = $value;
                }
            }
        }

        return [];
    }

    private function findDriverDeposit(?int $operationId, ?string $reference, ?string $providerReference, ?string $providerTransactionId): ?DriverDeposit
    {
        if ($operationId !== null) {
            $record = DriverDeposit::query()->find($operationId);
            if ($record) {
                return $record;
            }
        }

        foreach ([
            ['reference', $reference],
            ['provider_reference', $providerReference],
            ['provider_transaction_id', $providerTransactionId],
        ] as [$field, $value]) {
            if ($value !== null && trim($value) !== '') {
                $record = DriverDeposit::query()->where($field, trim($value))->first();

                if ($record) {
                    return $record;
                }
            }
        }

        return null;
    }

    private function findPassengerDeposit(?int $operationId, ?string $reference, ?string $providerReference, ?string $providerTransactionId): ?PassengerDeposit
    {
        if ($operationId !== null) {
            $record = PassengerDeposit::query()->find($operationId);
            if ($record) {
                return $record;
            }
        }

        foreach ([
            ['reference', $reference],
            ['provider_reference', $providerReference],
            ['provider_transaction_id', $providerTransactionId],
        ] as [$field, $value]) {
            if ($value !== null && trim($value) !== '') {
                $record = PassengerDeposit::query()->where($field, trim($value))->first();

                if ($record) {
                    return $record;
                }
            }
        }

        return null;
    }

    private function findDriverWithdrawal(?int $operationId, ?string $reference, ?string $providerReference, ?string $providerTransactionId): ?DriverWithdrawal
    {
        if ($operationId !== null) {
            $record = DriverWithdrawal::query()->find($operationId);
            if ($record) {
                return $record;
            }
        }

        foreach ([
            ['reference', $reference],
            ['provider_reference', $providerReference],
            ['provider_transaction_id', $providerTransactionId],
        ] as [$field, $value]) {
            if ($value !== null && trim($value) !== '') {
                $record = DriverWithdrawal::query()->where($field, trim($value))->first();

                if ($record) {
                    return $record;
                }
            }
        }

        return null;
    }

    private function toNullableInt(mixed $value): ?int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private function firstString(array $values): ?string
    {
        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }

            $string = trim((string) $value);

            if ($string !== '') {
                return $string;
            }
        }

        return null;
    }
}
