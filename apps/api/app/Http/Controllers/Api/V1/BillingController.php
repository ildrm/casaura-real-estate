<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\ApiException;
use App\Domain\Billing\BillingService;
use App\Domain\Tenancy\FeatureResolver;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\PromotionPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly FeatureResolver $features,
        private readonly BillingService $billing,
    ) {}

    public function show(): JsonResponse
    {
        $agency = $this->tenant->agency()->load('subscription.plan.entitlements');
        $plans = Plan::query()->where('is_active', true)->where('is_public', true)
            ->orderBy('price_amount_minor')->get();
        $invoices = Invoice::query()->where('agency_id', $agency->id)->latest('provider_updated_at')->limit(100)->get();

        return response()->json(['data' => [
            'agency_id' => $agency->id,
            'payments_enabled' => $this->features->resolve('payments', $agency)['enabled'],
            'plans' => $plans->map(fn (Plan $plan) => [
                'id' => $plan->id,
                'slug' => $plan->slug,
                'name' => $plan->name,
                'price' => ['amount_minor' => $plan->price_amount_minor, 'currency' => $plan->price_currency],
                'billing_interval' => $plan->billing_interval,
            ]),
            'subscription' => $agency->subscription ? [
                'id' => $agency->subscription->id,
                'plan_id' => $agency->subscription->plan_id,
                'status' => $agency->subscription->status,
                'billing_status' => $agency->subscription->billing_status,
                'current_period_ends_at' => $agency->subscription->current_period_ends_at,
                'cancel_at' => $agency->subscription->cancel_at,
                'entitlements' => $agency->subscription->plan->entitlements->map(fn ($entitlement) => [
                    'key' => $entitlement->key,
                    'value' => $entitlement->value,
                    'quota' => $entitlement->quota,
                ])->values(),
            ] : null,
            'invoices' => $invoices->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'status' => $invoice->status,
                'subtotal_minor' => $invoice->subtotal_minor,
                'tax_minor' => $invoice->tax_minor,
                'total_minor' => $invoice->total_minor,
                'currency' => $invoice->currency,
                'period_starts_at' => $invoice->period_starts_at,
                'period_ends_at' => $invoice->period_ends_at,
                'hosted_invoice_url' => $invoice->hosted_invoice_url,
                'invoice_pdf_url' => $invoice->invoice_pdf_url,
            ]),
            'promotion_policies' => PromotionPolicy::query()
                ->where('status', 'active')
                ->where('starts_at', '<=', now())
                ->where('ends_at', '>', now())
                ->latest('version')->get()
                ->filter(fn (PromotionPolicy $policy) => $agency->subscription && in_array(
                    $agency->subscription->plan_id,
                    $policy->eligible_plan_ids,
                    true,
                ))->values()->map(fn (PromotionPolicy $policy) => [
                    'id' => $policy->id,
                    'family_id' => $policy->family_id,
                    'version' => $policy->version,
                    'name' => $policy->name,
                    'placement' => $policy->placement,
                    'label' => $policy->label,
                    'disclosure' => $policy->disclosure,
                    'eligible_plan_ids' => $policy->eligible_plan_ids,
                    'starts_at' => $policy->starts_at,
                    'ends_at' => $policy->ends_at,
                    'max_impressions' => $policy->max_impressions,
                    'status' => $policy->status,
                ]),
        ]]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $agency = $this->tenant->agency();
        $this->features->ensureEnabled('payments', $agency);
        $validated = $request->validate(['plan_id' => ['required', 'uuid']]);
        $plan = Plan::query()->where('is_active', true)->where('is_public', true)
            ->where('price_currency', 'USD')->findOrFail($validated['plan_id']);
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '') {
            throw new ApiException('IDEMPOTENCY_KEY_REQUIRED', 'An Idempotency-Key header is required.', 422);
        }
        $result = $this->billing->checkout($request, $agency, $plan, $key);
        $session = $result['session'];

        return response()->json(['data' => [
            'id' => $session->id,
            'provider' => $result['provider'],
            'provider_session_id' => $session->provider_session_id,
            'url' => $session->url,
            'status' => $session->status,
            'expires_at' => $session->expires_at,
        ]], $result['created'] ? 201 : 200);
    }

    public function portal(): JsonResponse
    {
        $agency = $this->tenant->agency();
        $this->features->ensureEnabled('payments', $agency);

        return response()->json(['data' => $this->billing->portal($agency)], 201);
    }
}
