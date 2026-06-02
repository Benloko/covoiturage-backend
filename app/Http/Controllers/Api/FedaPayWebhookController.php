<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FedaPay\FedaPayGateway;
use App\Services\FedaPay\FedaPayWebhookHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class FedaPayWebhookController extends Controller
{
    public function __construct(
        private readonly FedaPayGateway $fedaPayGateway,
        private readonly FedaPayWebhookHandler $fedaPayWebhookHandler,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $rawPayload = (string) $request->getContent();
        $signature = $request->header('X-FEDAPAY-SIGNATURE')
            ?? $request->header('x-fedapay-signature');

        try {
            $payload = $this->fedaPayGateway->parseWebhookPayload($rawPayload, $signature);
        } catch (Throwable $exception) {
            Log::warning('FeDaPay webhook rejected.', [
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Webhook FeDaPay invalide.',
            ], 400);
        }

        try {
            $this->fedaPayWebhookHandler->handle($payload);
        } catch (Throwable $exception) {
            Log::error('FeDaPay webhook processing failed.', [
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Erreur interne webhook FeDaPay.',
            ], 500);
        }

        return response()->json([
            'received' => true,
        ]);
    }
}
