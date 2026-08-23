<?php

namespace Tests\Feature\Api;

use App\Domain\Ai\OpenAiResponsesProvider;
use App\Domain\Billing\StripeBillingProvider;
use App\Models\Agency;
use App\Models\Plan;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProviderAdaptersTest extends TestCase
{
    public function test_openai_responses_adapter_uses_non_stored_strict_grounded_output(): void
    {
        config([
            'ai.api_key' => 'sk-provider-contract-test',
            'ai.base_url' => 'https://api.openai.com',
            'ai.model' => 'gpt-5-mini',
        ]);
        Http::fake(['https://api.openai.com/v1/responses' => Http::response([
            'model' => 'gpt-5-mini-2026-08-01',
            'output' => [[
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode([
                        'text' => '<b>Grounded answer.</b>',
                        'title' => null,
                        'description' => null,
                    ], JSON_THROW_ON_ERROR),
                ]],
            ]],
            'usage' => ['input_tokens' => 120, 'output_tokens' => 24],
        ])]);

        $result = app(OpenAiResponsesProvider::class)->generate('search', 'Find a house.', [[
            'listing_id' => 'listing-one',
            'title' => 'Current home',
        ]]);

        $this->assertSame('Grounded answer.', $result['text']);
        $this->assertSame('gpt-5-mini-2026-08-01', $result['model']);
        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();

            return $request->url() === 'https://api.openai.com/v1/responses'
                && $request->hasHeader('Authorization', 'Bearer sk-provider-contract-test')
                && $payload['store'] === false
                && $payload['text']['format']['type'] === 'json_schema'
                && $payload['text']['format']['strict'] === true
                && $payload['text']['format']['schema']['additionalProperties'] === false;
        });
    }

    public function test_stripe_adapter_uses_hosted_checkout_tax_portal_and_idempotency(): void
    {
        config([
            'billing.stripe.secret_key' => 'sk_test_provider_contract',
            'billing.stripe.api_url' => 'https://api.stripe.com',
            'billing.stripe.api_version' => '2025-07-30.basil',
            'billing.checkout_success_url' => 'https://www.casaura.example/agency/billing?checkout=success',
            'billing.checkout_cancel_url' => 'https://www.casaura.example/agency/billing?checkout=cancelled',
            'billing.portal_return_url' => 'https://www.casaura.example/agency/billing',
        ]);
        Http::fake([
            'https://api.stripe.com/v1/customers' => Http::response(['id' => 'cus_contract']),
            'https://api.stripe.com/v1/checkout/sessions' => Http::response([
                'id' => 'cs_contract',
                'url' => 'https://checkout.stripe.com/c/pay/cs_contract',
                'expires_at' => now()->addMinutes(30)->timestamp,
            ]),
            'https://api.stripe.com/v1/billing_portal/sessions' => Http::response([
                'id' => 'bps_contract',
                'url' => 'https://billing.stripe.com/p/session/bps_contract',
            ]),
        ]);
        $agency = new Agency(['name' => 'Adapter Agency', 'email' => 'billing@example.com']);
        $agency->id = (string) str()->uuid();
        $plan = new Plan([
            'name' => 'Professional',
            'slug' => 'professional',
            'price_amount_minor' => 4900,
            'price_currency' => 'USD',
            'billing_interval' => 'month',
            'provider_price_id' => 'price_contract',
        ]);
        $plan->id = (string) str()->uuid();
        $provider = app(StripeBillingProvider::class);

        $customer = $provider->createCustomer($agency, 'customer-key');
        $checkout = $provider->createCheckout($agency, $plan, $customer['id'], 'checkout-key');
        $portal = $provider->createPortal($customer['id']);

        $this->assertSame('https://checkout.stripe.com/c/pay/cs_contract', $checkout['url']);
        $this->assertSame('https://billing.stripe.com/p/session/bps_contract', $portal['url']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
            && $request->hasHeader('Idempotency-Key', 'checkout-key')
            && $request['mode'] === 'subscription'
            && $request['automatic_tax']['enabled'] === true
            && $request['success_url'] === 'https://www.casaura.example/agency/billing?checkout=success'
            && $request['cancel_url'] === 'https://www.casaura.example/agency/billing?checkout=cancelled');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.stripe.com/v1/billing_portal/sessions'
            && $request['customer'] === 'cus_contract'
            && $request['return_url'] === 'https://www.casaura.example/agency/billing');
    }
}
