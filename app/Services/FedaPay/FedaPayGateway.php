<?php

namespace App\Services\FedaPay;

use App\Models\User;
use App\Rules\BeninPhoneNumber;
use FedaPay\Error\Base as FedaPayError;
use FedaPay\FedaPay;
use FedaPay\FedaPayObject;
use FedaPay\Payout;
use FedaPay\Transaction;
use FedaPay\Webhook;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class FedaPayGateway
{
    public function isEnabled(): bool
    {
        if (! config('fedapay.enabled')) {
            return false;
        }

        return trim((string) config('fedapay.secret_key')) !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function initiateDeposit(User $user, string $network, string $phone, int $amountFcfa, string $reference, string $operation, int $operationId): array
    {
        $this->ensureEnabled();
        $this->configureClient();

        $customer = $this->buildCustomerPayload($user, $phone);
        $phoneNumber = is_array($customer['phone_number'] ?? null) ? $customer['phone_number'] : null;
        $callbackUrl = trim((string) config('fedapay.callback_url'));

        $payload = [
            'description' => 'Recharge '.$reference,
            'amount' => $amountFcfa,
            'currency' => ['iso' => (string) config('fedapay.currency_iso', 'XOF')],
            'customer' => $customer,
            'custom_metadata' => [
                'app' => 'co-voiturage',
                'operation' => $operation,
                'operation_id' => $operationId,
                'app_reference' => $reference,
                'user_id' => $user->id,
            ],
        ];

        if ($callbackUrl !== '') {
            $payload['callback_url'] = $callbackUrl;
        }

        try {
            $transaction = Transaction::create($payload);
            $tokenObject = $transaction->generateToken();
            $token = trim((string) ($tokenObject->token ?? ''));

            if ($token === '') {
                throw new RuntimeException('Token de paiement FeDaPay introuvable.');
            }

            $mode = $this->transactionModeForNetwork($network);

            $sendPayload = [];
            if ($phoneNumber !== null) {
                $sendPayload['phone_number'] = $phoneNumber;
            }

            $sendObject = $transaction->sendNowWithToken($mode, $token, $sendPayload);

            $transactionArray = $this->toArray($transaction);
            $sendArray = $this->toArray($sendObject);
            $providerStatus = $this->extractProviderStatus([$transactionArray, $sendArray]);

            return [
                'provider' => 'fedapay',
                'provider_transaction_id' => isset($transactionArray['id']) ? (string) $transactionArray['id'] : null,
                'provider_reference' => isset($transactionArray['reference']) ? (string) $transactionArray['reference'] : null,
                'provider_status' => $providerStatus,
                'provider_payload' => [
                    'transaction' => $transactionArray,
                    'token' => $this->toArray($tokenObject),
                    'send' => $sendArray,
                ],
                'local_status' => $this->resolveLocalStatus($providerStatus),
            ];
        } catch (FedaPayError $exception) {
            throw new RuntimeException(
                $this->formatProviderError($exception, 'Echec de communication avec FeDaPay pendant la recharge.'),
                0,
                $exception
            );
        } catch (Throwable $exception) {
            throw new RuntimeException('Erreur technique pendant la demande de recharge FeDaPay.', 0, $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function initiateWithdrawal(User $user, string $network, string $phone, int $amountFcfa, string $reference, string $operation, int $operationId): array
    {
        $this->ensureEnabled();
        $this->configureClient();

        $customer = $this->buildCustomerPayload($user, $phone);
        $phoneNumber = is_array($customer['phone_number'] ?? null) ? $customer['phone_number'] : null;
        $callbackUrl = trim((string) config('fedapay.callback_url'));

        $payload = [
            'amount' => $amountFcfa,
            'currency' => ['iso' => (string) config('fedapay.currency_iso', 'XOF')],
            'mode' => $this->payoutModeForNetwork($network),
            'customer' => $customer,
            'custom_metadata' => [
                'app' => 'co-voiturage',
                'operation' => $operation,
                'operation_id' => $operationId,
                'app_reference' => $reference,
                'user_id' => $user->id,
            ],
        ];

        if ($callbackUrl !== '') {
            $payload['callback_url'] = $callbackUrl;
        }

        try {
            $payout = Payout::create($payload);

            $startPayload = [];
            if ($phoneNumber !== null) {
                $startPayload['phone_number'] = $phoneNumber;
            }

            $startObject = $payout->sendNow($startPayload);

            $payoutArray = $this->toArray($payout);
            $startArray = $this->toArray($startObject);
            $providerStatus = $this->extractProviderStatus([$payoutArray, $startArray]);

            return [
                'provider' => 'fedapay',
                'provider_transaction_id' => isset($payoutArray['id']) ? (string) $payoutArray['id'] : null,
                'provider_reference' => isset($payoutArray['reference']) ? (string) $payoutArray['reference'] : null,
                'provider_status' => $providerStatus,
                'provider_payload' => [
                    'payout' => $payoutArray,
                    'start' => $startArray,
                ],
                'local_status' => $this->resolveLocalStatus($providerStatus),
            ];
        } catch (FedaPayError $exception) {
            throw new RuntimeException(
                $this->formatProviderError($exception, 'Echec de communication avec FeDaPay pendant le retrait.'),
                0,
                $exception
            );
        } catch (Throwable $exception) {
            throw new RuntimeException('Erreur technique pendant la demande de retrait FeDaPay.', 0, $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function parseWebhookPayload(string $rawPayload, ?string $signature): array
    {
        $secret = trim((string) config('fedapay.webhook_secret'));

        if ($secret !== '') {
            if (! $signature) {
                throw new RuntimeException('Signature webhook FeDaPay absente.');
            }

            $event = Webhook::constructEvent(
                $rawPayload,
                $signature,
                $secret,
                max(0, (int) config('fedapay.webhook_tolerance', 300))
            );

            $payload = $this->toArray($event);
        } else {
            $decoded = json_decode($rawPayload, true);

            if (! is_array($decoded)) {
                throw new RuntimeException('Payload webhook FeDaPay invalide.');
            }

            $payload = $decoded;
        }

        $this->hydrateWebhookEntityIfPossible($payload);

        return $payload;
    }

    public function resolveLocalStatus(?string $providerStatus, ?string $eventName = null): string
    {
        $event = Str::lower(trim((string) $eventName));

        if ($event !== '') {
            if (
                str_ends_with($event, '.approved')
                || str_ends_with($event, '.transferred')
                || str_ends_with($event, '.sent')
                || str_ends_with($event, '.completed')
            ) {
                return 'completed';
            }

            if (
                str_ends_with($event, '.declined')
                || str_ends_with($event, '.failed')
                || str_ends_with($event, '.canceled')
                || str_ends_with($event, '.cancelled')
                || str_ends_with($event, '.rejected')
            ) {
                return 'failed';
            }
        }

        $status = Str::lower(trim((string) $providerStatus));

        if ($status === '') {
            return 'processing';
        }

        if (in_array($status, ['approved', 'transferred', 'sent', 'success', 'successful', 'paid', 'completed'], true)) {
            return 'completed';
        }

        if (in_array($status, ['declined', 'failed', 'canceled', 'cancelled', 'rejected', 'expired', 'error'], true)) {
            return 'failed';
        }

        return 'processing';
    }

    private function ensureEnabled(): void
    {
        if (! $this->isEnabled()) {
            throw new RuntimeException('Integration FeDaPay non activee.');
        }
    }

    private function configureClient(): void
    {
        $secretKey = trim((string) config('fedapay.secret_key'));

        if ($secretKey === '') {
            throw new RuntimeException('Cle secrete FeDaPay manquante.');
        }

        FedaPay::setApiKey($secretKey);
        FedaPay::setEnvironment((string) config('fedapay.environment', 'sandbox'));

        $apiBase = trim((string) config('fedapay.api_base'));

        if ($apiBase !== '') {
            FedaPay::setApiBase(rtrim($apiBase, '/'));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCustomerPayload(User $user, string $phone): array
    {
        $normalizedPhone = BeninPhoneNumber::normalizeAny($phone);

        if (! $normalizedPhone) {
            throw new RuntimeException('Numero de telephone invalide pour FeDaPay.');
        }

        $fullName = trim((string) $user->name);
        $nameParts = preg_split('/\s+/', $fullName) ?: [];
        $firstName = (string) ($nameParts[0] ?? 'Client');
        $lastName = trim(implode(' ', array_slice($nameParts, 1)));

        if ($lastName === '') {
            $lastName = 'Utilisateur';
        }

        return [
            'firstname' => $firstName,
            'lastname' => $lastName,
            'email' => $user->email,
            'phone_number' => [
                'number' => $normalizedPhone,
                'country' => (string) config('fedapay.phone_country', 'BJ'),
            ],
        ];
    }

    private function transactionModeForNetwork(string $network): string
    {
        $mode = trim((string) data_get(config('fedapay.transaction_modes', []), $network));

        if ($mode === '') {
            throw new RuntimeException('Mode FeDaPay non configure pour ce reseau de recharge.');
        }

        return $mode;
    }

    private function payoutModeForNetwork(string $network): string
    {
        $mode = trim((string) data_get(config('fedapay.payout_modes', []), $network));

        if ($mode === '') {
            throw new RuntimeException('Mode FeDaPay non configure pour ce reseau de retrait.');
        }

        return $mode;
    }

    /**
     * @param  array<int, array<string, mixed>>  $sources
     */
    private function extractProviderStatus(array $sources): ?string
    {
        foreach ($sources as $source) {
            foreach (['status', 'payment_intent.status', 'payment_intents.0.status', 'payouts.0.status', '0.status'] as $path) {
                $value = data_get($source, $path);

                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(mixed $value): array
    {
        if ($value instanceof FedaPayObject) {
            return $value->__toArray(true);
        }

        if (is_array($value)) {
            return $value;
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hydrateWebhookEntityIfPossible(array &$payload): void
    {
        $entity = Str::lower(trim((string) ($payload['entity'] ?? '')));
        $objectId = $payload['object_id'] ?? null;

        if (! in_array($entity, ['transaction', 'payout'], true) || ! is_numeric($objectId)) {
            return;
        }

        if (! $this->isEnabled()) {
            return;
        }

        try {
            $this->configureClient();

            if ($entity === 'transaction') {
                $payload['resolved_object'] = $this->toArray(Transaction::retrieve((int) $objectId));

                return;
            }

            $payload['resolved_object'] = $this->toArray(Payout::retrieve((int) $objectId));
        } catch (Throwable) {
            // Ignore retrieval issues to keep webhook processing resilient.
        }
    }

    private function formatProviderError(FedaPayError $exception, string $fallback): string
    {
        $message = trim((string) ($exception->getErrorMessage() ?: $exception->getMessage()));

        return $message !== '' ? $message : $fallback;
    }
}
