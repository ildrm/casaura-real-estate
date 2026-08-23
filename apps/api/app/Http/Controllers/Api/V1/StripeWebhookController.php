<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\StripeWebhookService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeWebhookService $webhooks): JsonResponse
    {
        $payload = $request->getContent();
        $event = $webhooks->verify($payload, (string) $request->header('Stripe-Signature'));
        $processed = $webhooks->process($event, $payload);

        return response()->json(['received' => true, 'processed' => $processed]);
    }
}
