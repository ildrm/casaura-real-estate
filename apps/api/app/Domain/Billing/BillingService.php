<?php

namespace App\Domain\Billing;

use App\Domain\ApiException;
use App\Domain\Tenancy\AuditRecorder;
use App\Models\Agency;
use App\Models\BillingCheckoutSession;
use App\Models\BillingCustomer;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class BillingService
{
    public function __construct(private readonly BillingProvider $provider, private readonly AuditRecorder $audit) {}

    public function checkout(Request $request, Agency $agency, Plan $plan, string $idempotencyKey): array
    {
        $hash = hash('sha256', json_encode([
            'agency_id' => $agency->id,
            'plan_id' => $plan->id,
            'actor_id' => $request->user()->id,
        ], JSON_THROW_ON_ERROR));
        $existing = BillingCheckoutSession::query()->where('agency_id', $agency->id)
            ->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            if (! hash_equals($existing->payload_hash, $hash)) {
                throw new ApiException('IDEMPOTENCY_CONFLICT', 'The idempotency key was used with another checkout.', 409);
            }

            return ['session' => $existing, 'created' => false, 'provider' => $this->provider->adapter()];
        }

        $customer = $this->customer($agency, $idempotencyKey);
        $providerSession = $this->provider->createCheckout(
            $agency,
            $plan,
            $customer->provider_customer_id,
            $idempotencyKey,
        );
        $session = DB::transaction(function () use ($request, $agency, $plan, $idempotencyKey, $hash, $providerSession): BillingCheckoutSession {
            $session = BillingCheckoutSession::query()->create([
                'agency_id' => $agency->id,
                'plan_id' => $plan->id,
                'actor_user_id' => $request->user()->id,
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $hash,
                'provider_session_id' => $providerSession['id'],
                'status' => 'open',
                'url' => $providerSession['url'],
                'expires_at' => $providerSession['expires_at'],
            ]);
            $this->audit->record($request, 'billing.checkout_created', $session, null, [
                'plan_id' => $plan->id,
                'provider' => $this->provider->adapter(),
            ], $agency->id);

            return $session;
        });

        return ['session' => $session, 'created' => true, 'provider' => $this->provider->adapter()];
    }

    /** @return array{id: string, url: string, provider: string} */
    public function portal(Agency $agency): array
    {
        $customer = $this->customer($agency, 'portal-customer-'.$agency->id);
        $session = $this->provider->createPortal($customer->provider_customer_id);

        return [...$session, 'provider' => $this->provider->adapter()];
    }

    private function customer(Agency $agency, string $idempotencyKey): BillingCustomer
    {
        $customer = BillingCustomer::query()->where('agency_id', $agency->id)
            ->where('provider', $this->provider->adapter() === 'deterministic' ? 'stripe' : $this->provider->adapter())
            ->first();
        if ($customer) {
            return $customer;
        }
        $providerCustomer = $this->provider->createCustomer($agency, 'customer-'.$idempotencyKey);

        return BillingCustomer::query()->create([
            'agency_id' => $agency->id,
            'provider' => 'stripe',
            'provider_customer_id' => $providerCustomer['id'],
        ]);
    }
}
