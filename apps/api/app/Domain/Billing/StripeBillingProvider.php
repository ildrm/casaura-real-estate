<?php

namespace App\Domain\Billing;

use App\Domain\ApiException;
use App\Models\Agency;
use App\Models\Plan;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class StripeBillingProvider implements BillingProvider
{
    public function createCustomer(Agency $agency, string $idempotencyKey): array
    {
        $response = $this->request($idempotencyKey)->post($this->url('/v1/customers'), [
            'name' => $agency->name,
            'email' => $agency->email,
            'metadata' => ['agency_id' => $agency->id],
        ]);

        return ['id' => $this->requiredString($response->json(), 'id')];
    }

    public function createCheckout(Agency $agency, Plan $plan, string $customerId, string $idempotencyKey): array
    {
        if (! $plan->provider_price_id) {
            throw new ApiException('BILLING_PRICE_UNCONFIGURED', 'The selected plan is not configured for checkout.', 422);
        }
        $response = $this->request($idempotencyKey)->post($this->url('/v1/checkout/sessions'), [
            'mode' => 'subscription',
            'customer' => $customerId,
            'client_reference_id' => $agency->id,
            'line_items' => [['price' => $plan->provider_price_id, 'quantity' => 1]],
            'automatic_tax' => ['enabled' => true],
            'success_url' => config('billing.checkout_success_url'),
            'cancel_url' => config('billing.checkout_cancel_url'),
            'metadata' => ['agency_id' => $agency->id, 'plan_id' => $plan->id],
            'subscription_data' => ['metadata' => ['agency_id' => $agency->id, 'plan_id' => $plan->id]],
        ]);
        $body = $response->json();

        return [
            'id' => $this->requiredString($body, 'id'),
            'url' => $this->providerUrl($this->requiredString($body, 'url')),
            'expires_at' => isset($body['expires_at']) ? now()->setTimestamp((int) $body['expires_at'])->toISOString() : null,
        ];
    }

    public function createPortal(string $customerId): array
    {
        $response = $this->request()->post($this->url('/v1/billing_portal/sessions'), [
            'customer' => $customerId,
            'return_url' => config('billing.portal_return_url'),
        ]);
        $body = $response->json();

        return [
            'id' => $this->requiredString($body, 'id'),
            'url' => $this->providerUrl($this->requiredString($body, 'url')),
        ];
    }

    public function adapter(): string
    {
        return 'stripe';
    }

    private function request(?string $idempotencyKey = null): PendingRequest
    {
        $secret = (string) config('billing.stripe.secret_key');
        if ($secret === '') {
            throw new ApiException('BILLING_PROVIDER_UNAVAILABLE', 'Stripe is not configured.', 503);
        }
        $request = Http::asForm()->withBasicAuth($secret, '')
            ->acceptJson()->timeout(15)
            ->withHeaders(['Stripe-Version' => (string) config('billing.stripe.api_version')]);
        if ($idempotencyKey) {
            $request = $request->withHeaders(['Idempotency-Key' => $idempotencyKey]);
        }

        return $request->throw(fn ($response, $exception) => throw new ApiException(
            'BILLING_PROVIDER_UNAVAILABLE',
            'The billing provider request failed.',
            503,
        ));
    }

    private function url(string $path): string
    {
        return rtrim((string) config('billing.stripe.api_url'), '/').$path;
    }

    /** @param array<string, mixed> $data */
    private function requiredString(array $data, string $key): string
    {
        if (! is_string($data[$key] ?? null) || $data[$key] === '') {
            throw new ApiException('BILLING_PROVIDER_RESPONSE_INVALID', 'Stripe returned an invalid response.', 502);
        }

        return $data[$key];
    }

    private function providerUrl(string $url): string
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host']) ||
            (! str_ends_with($parts['host'], '.stripe.com') && ! str_ends_with($parts['host'], '.stripe.test'))) {
            throw new ApiException('BILLING_PROVIDER_RESPONSE_INVALID', 'Stripe returned an invalid hosted URL.', 502);
        }

        return $url;
    }
}
