<?php

namespace Tests\Feature\Api;

use App\Models\AgencyMember;
use App\Models\FeatureFlag;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesAgencyTenant;
use Tests\TestCase;

class MonetizationTest extends TestCase
{
    use CreatesAgencyTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('listing_media');
        config([
            'billing.driver' => 'deterministic',
            'billing.stripe.webhook_secret' => 'whsec_test',
        ]);
        FeatureFlag::query()->whereIn('key', ['payments', 'sponsored_listings'])
            ->update(['default_enabled' => true]);
        $launchPlanId = DB::table('plans')->where('slug', 'launch')->value('id');
        foreach (['payments', 'sponsored_listings'] as $key) {
            PlanEntitlement::query()->updateOrCreate(
                ['plan_id' => $launchPlanId, 'key' => $key],
                ['value' => true],
            );
        }
    }

    /** Phase 10 AC-1 through AC-4. */
    public function test_billing_workspace_checkout_and_portal_are_tenant_scoped_and_idempotent(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        $plan = $this->paidPlan();
        $this->actAsAgencyOwner($owner);
        $workspace = $this->getJson('/api/v1/billing', $this->agencyHeaders($agency))
            ->assertOk()->json('data');
        $this->assertSame($agency->id, $workspace['agency_id']);
        $this->assertContains($plan->id, collect($workspace['plans'])->pluck('id')->all());

        $headers = $this->agencyHeaders($agency, ['Idempotency-Key' => 'checkout-one']);
        $checkout = $this->postJson('/api/v1/billing/checkout-sessions', [
            'plan_id' => $plan->id,
        ], $headers)->assertCreated()->json('data');
        $this->assertStringStartsWith('https://checkout.stripe.test/', $checkout['url']);
        $this->postJson('/api/v1/billing/checkout-sessions', [
            'plan_id' => $plan->id,
        ], $headers)->assertOk()->assertJsonPath('data.id', $checkout['id']);

        $this->postJson('/api/v1/billing/portal-sessions', [], $this->agencyHeaders($agency))
            ->assertCreated()->assertJsonPath('data.provider', 'deterministic');
    }

    /** Phase 10 AC-5 and AC-6. */
    public function test_stripe_webhook_signature_is_verified_and_invoice_projection_is_idempotent(): void
    {
        [, $agency] = $this->createAgencyOwner();
        DB::table('billing_customers')->insert([
            'id' => (string) str()->uuid(),
            'agency_id' => $agency->id,
            'provider' => 'stripe',
            'provider_customer_id' => 'cus_test',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $event = [
            'id' => 'evt_invoice_paid',
            'type' => 'invoice.paid',
            'created' => now()->timestamp,
            'data' => ['object' => [
                'id' => 'in_test',
                'customer' => 'cus_test',
                'subscription' => 'sub_test',
                'number' => 'INV-100',
                'status' => 'paid',
                'subtotal' => 4900,
                'tax' => 400,
                'total' => 5300,
                'currency' => 'usd',
                'period_start' => now()->startOfMonth()->timestamp,
                'period_end' => now()->endOfMonth()->timestamp,
                'hosted_invoice_url' => 'https://invoice.stripe.test/in_test',
                'invoice_pdf' => 'https://invoice.stripe.test/in_test.pdf',
            ]],
        ];
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test');

        $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $payload)->assertOk();
        $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $payload)->assertOk();
        $this->assertDatabaseCount('billing_events', 1);
        $this->assertDatabaseCount('invoices', 1);

        $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1=invalid",
        ], $payload)->assertStatus(400);
        $this->assertDatabaseCount('billing_events', 1);
    }

    /** Phase 10 AC-5, AC-6, and EC-2 through EC-3. */
    public function test_stripe_payment_refund_dispute_and_customer_events_are_redacted_and_monotonic(): void
    {
        [, $agency] = $this->createAgencyOwner();
        DB::table('billing_customers')->insert([
            'id' => (string) str()->uuid(),
            'agency_id' => $agency->id,
            'provider' => 'stripe',
            'provider_customer_id' => 'cus_lifecycle',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $base = now()->timestamp;

        $this->postStripeEvent([
            'id' => 'evt_payment_failed',
            'type' => 'payment_intent.payment_failed',
            'created' => $base + 20,
            'data' => ['object' => [
                'id' => 'pi_lifecycle',
                'object' => 'payment_intent',
                'customer' => 'cus_lifecycle',
            ]],
        ])->assertOk();
        $this->assertDatabaseHas('subscriptions', [
            'agency_id' => $agency->id,
            'billing_status' => 'past_due',
        ]);

        $this->postStripeEvent([
            'id' => 'evt_payment_succeeded_older',
            'type' => 'payment_intent.succeeded',
            'created' => $base + 10,
            'data' => ['object' => [
                'id' => 'pi_lifecycle',
                'object' => 'payment_intent',
                'customer' => 'cus_lifecycle',
            ]],
        ])->assertOk();
        $this->assertDatabaseHas('subscriptions', [
            'agency_id' => $agency->id,
            'billing_status' => 'past_due',
        ]);

        $this->postStripeEvent([
            'id' => 'evt_refund',
            'type' => 'refund.created',
            'created' => $base + 30,
            'data' => ['object' => [
                'id' => 're_lifecycle',
                'object' => 'refund',
                'payment_intent' => 'pi_lifecycle',
                'status' => 'succeeded',
            ]],
        ])->assertOk();
        $this->assertDatabaseHas('subscriptions', [
            'agency_id' => $agency->id,
            'billing_status' => 'unpaid',
        ]);
        $this->assertDatabaseHas('billing_events', [
            'provider_event_id' => 'evt_refund',
            'provider_customer_id' => 'cus_lifecycle',
            'agency_id' => $agency->id,
            'status' => 'processed',
        ]);

        $this->postStripeEvent([
            'id' => 'evt_dispute',
            'type' => 'charge.dispute.created',
            'created' => $base + 40,
            'data' => ['object' => [
                'id' => 'dp_lifecycle',
                'object' => 'dispute',
                'payment_intent' => 'pi_lifecycle',
                'status' => 'needs_response',
            ]],
        ])->assertOk();
        $this->assertDatabaseHas('subscriptions', [
            'agency_id' => $agency->id,
            'billing_status' => 'past_due',
        ]);

        $this->postStripeEvent([
            'id' => 'evt_customer_deleted',
            'type' => 'customer.deleted',
            'created' => $base + 50,
            'data' => ['object' => [
                'id' => 'cus_lifecycle',
                'object' => 'customer',
                'deleted' => true,
            ]],
        ])->assertOk();
        $this->assertDatabaseHas('subscriptions', [
            'agency_id' => $agency->id,
            'status' => 'inactive',
            'billing_status' => 'canceled',
        ]);

        $this->postStripeEvent([
            'id' => 'evt_unknown_customer',
            'type' => 'payment_intent.payment_failed',
            'created' => $base + 60,
            'data' => ['object' => [
                'id' => 'pi_unknown',
                'object' => 'payment_intent',
                'customer' => 'cus_unknown',
            ]],
        ])->assertOk();
        $this->assertDatabaseHas('billing_events', [
            'provider_event_id' => 'evt_unknown_customer',
            'provider_object_id' => 'pi_unknown',
            'provider_customer_id' => 'cus_unknown',
            'agency_id' => null,
            'status' => 'unresolved',
            'failure_code' => 'BILLING_CUSTOMER_UNKNOWN',
        ]);
    }

    /** Phase 10 AC-7 through AC-9. */
    public function test_promotion_policy_campaign_and_public_inventory_are_versioned_eligible_and_labelled(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        $listing = $this->createPublishedListing($owner, $agency, ['reference' => 'SPONSORED-ONE']);
        $plan = $this->paidPlan();
        DB::table('subscriptions')->where('agency_id', $agency->id)->update([
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_status' => 'paid',
            'current_period_ends_at' => now()->addMonth(),
        ]);

        $admin = $this->createPlatformOperator();
        Sanctum::actingAs($admin, ['*', 'mfa']);
        $policy = $this->postJson('/api/v1/admin/promotion-policies', [
            'name' => 'Search featured placement',
            'placement' => 'search',
            'label' => 'Sponsored',
            'disclosure' => 'Paid placement',
            'eligible_plan_ids' => [$plan->id],
            'starts_at' => now()->subMinute()->toISOString(),
            'ends_at' => now()->addMonth()->toISOString(),
            'max_impressions' => 1000,
        ])->assertCreated()->json('data');

        $this->actAsAgencyOwner($owner);
        $campaign = $this->postJson('/api/v1/billing/promotion-campaigns', [
            'listing_id' => $listing['id'],
            'policy_id' => $policy['id'],
            'starts_at' => now()->toISOString(),
            'ends_at' => now()->addWeek()->toISOString(),
            'impression_cap' => 100,
        ], $this->agencyHeaders($agency))->assertCreated()->json('data');
        $this->assertSame('active', $campaign['status']);

        $public = $this->getJson('/api/v1/public/sponsored-listings?placement=search')
            ->assertOk()->json('data.0');
        $this->assertSame('Sponsored', $public['label']);
        $this->assertTrue($public['sponsored']);
        $this->assertSame($listing['id'], $public['listing']['id']);
        $this->getJson('/api/v1/public/sponsored-listings?placement=search')
            ->assertOk()->assertJsonCount(0, 'data');
        DB::table('search_documents')->where('listing_id', $listing['id'])->delete();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.42'])
            ->getJson('/api/v1/public/sponsored-listings?placement=search')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('promotion_campaigns', [
            'id' => $campaign['id'],
            'status' => 'paused',
            'version' => 2,
        ]);
    }

    private function paidPlan(): Plan
    {
        $plan = Plan::query()->firstOrCreate(['slug' => 'professional'], [
            'name' => 'Professional',
            'is_active' => true,
            'is_public' => true,
            'price_amount_minor' => 4900,
            'price_currency' => 'USD',
            'billing_interval' => 'month',
            'provider_price_id' => 'price_professional',
        ]);
        foreach (['payments', 'sponsored_listings', 'listing_creation'] as $key) {
            PlanEntitlement::query()->updateOrCreate(
                ['plan_id' => $plan->id, 'key' => $key],
                ['value' => true, 'quota' => $key === 'listing_creation' ? 1000 : null],
            );
        }

        return $plan;
    }

    private function createPlatformOperator(): User
    {
        [$user, $agency] = $this->createAgencyOwner('Promotion Operations '.str()->random(5));
        $membership = AgencyMember::query()->where('agency_id', $agency->id)->where('user_id', $user->id)->firstOrFail();
        $membership->roles()->sync([Role::query()->where('slug', 'platform_administrator')->firstOrFail()->id]);

        return $user;
    }

    /** @param array<string, mixed> $event */
    private function postStripeEvent(array $event): TestResponse
    {
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test');

        return $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $payload);
    }
}
