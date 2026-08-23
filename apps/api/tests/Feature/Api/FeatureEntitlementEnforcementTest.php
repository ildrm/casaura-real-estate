<?php

namespace Tests\Feature\Api;

use App\Domain\Tenancy\FeatureResolver;
use App\Models\FeatureFlag;
use App\Models\FeatureFlagOverride;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesAgencyTenant;
use Tests\TestCase;

class FeatureEntitlementEnforcementTest extends TestCase
{
    use CreatesAgencyTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('listing_media');
    }

    /** P0 entitlements AC-1 through AC-4. */
    public function test_plan_gated_resolution_requires_an_active_unexpired_subscription_and_entitlement(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        $resolver = app(FeatureResolver::class);

        $this->assertSame([
            'enabled' => true,
            'value' => true,
            'source' => 'plan',
        ], $resolver->resolve('listing_creation', $agency));

        FeatureFlagOverride::query()->create([
            'feature_flag_id' => FeatureFlag::query()->where('key', 'listing_creation')->firstOrFail()->id,
            'scope_type' => 'agency',
            'scope_id' => $agency->id,
            'enabled' => true,
        ]);
        $subscription = Subscription::query()->where('agency_id', $agency->id)->firstOrFail();
        $subscription->update(['status' => 'inactive']);

        $this->assertSame('subscription', $resolver->resolve('listing_creation', $agency)['source']);
        $this->assertFalse($resolver->resolve('listing_creation', $agency)['enabled']);

        $this->actAsAgencyOwner($owner);
        $this->postJson('/api/v1/listings', $this->validListingPayload(), $this->agencyHeaders($agency))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FEATURE_DISABLED');
        $this->assertDatabaseCount('properties', 0);
        $this->assertDatabaseCount('listings', 0);

        $subscription->update(['status' => 'active', 'current_period_ends_at' => now()->subSecond()]);
        $this->assertFalse($resolver->resolve('listing_creation', $agency)['enabled']);

        FeatureFlagOverride::query()->where('scope_type', 'agency')->where('scope_id', $agency->id)->delete();

        $planWithoutEntitlement = Plan::query()->create([
            'name' => 'Restricted',
            'slug' => 'restricted',
            'is_active' => true,
            'is_public' => false,
            'price_amount_minor' => 0,
            'price_currency' => 'USD',
            'billing_interval' => 'month',
        ]);
        $subscription->update(['plan_id' => $planWithoutEntitlement->id, 'current_period_ends_at' => now()->addMonth()]);
        $this->assertSame('plan', $resolver->resolve('listing_creation', $agency)['source']);
        $this->assertFalse($resolver->resolve('listing_creation', $agency)['enabled']);
    }

    /** P0 entitlements AC-5. */
    public function test_listing_creation_enforces_the_plan_quota_without_partial_rows(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        PlanEntitlement::query()->where('key', 'listing_creation')->update(['quota' => 1]);
        $this->actAsAgencyOwner($owner);

        $this->postJson('/api/v1/listings', $this->validListingPayload(['reference' => 'QUOTA-ONE']), $this->agencyHeaders($agency))
            ->assertCreated();
        $this->postJson('/api/v1/listings', $this->validListingPayload(['reference' => 'QUOTA-TWO']), $this->agencyHeaders($agency))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'LISTING_QUOTA_EXCEEDED');

        $this->assertDatabaseCount('properties', 1);
        $this->assertDatabaseCount('property_identifiers', 1);
        $this->assertDatabaseCount('listings', 1);
        $this->assertDatabaseCount('listing_versions', 1);
    }

    /** P0 entitlements AC-6. */
    public function test_team_routes_enforce_the_feature_and_authoritative_quota(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        $entitlement = PlanEntitlement::query()->where('key', 'team_management')->firstOrFail();
        $entitlement->update(['value' => false]);
        $this->actAsAgencyOwner($owner);

        $this->getJson('/api/v1/agency/team', $this->agencyHeaders($agency))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FEATURE_DISABLED');
        $this->postJson('/api/v1/agency/team', [
            'name' => 'Blocked Member',
            'email' => 'blocked@example.com',
            'role_slug' => 'agent',
        ], $this->agencyHeaders($agency))->assertForbidden()->assertJsonPath('error.code', 'FEATURE_DISABLED');

        $entitlement->update(['value' => true, 'quota' => 1]);
        $this->postJson('/api/v1/agency/team', [
            'name' => 'Over Quota',
            'email' => 'quota@example.com',
            'role_slug' => 'agent',
        ], $this->agencyHeaders($agency))->assertUnprocessable()->assertJsonPath('error.code', 'TEAM_QUOTA_EXCEEDED');
        $this->assertDatabaseCount('agency_members', 1);
    }

    /** P0 entitlements AC-7. */
    public function test_media_storage_quota_counts_the_proposed_original_and_derivatives_and_cleans_up(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        $this->actAsAgencyOwner($owner);
        $listing = $this->postJson('/api/v1/listings', $this->validListingPayload(), $this->agencyHeaders($agency))
            ->assertCreated()
            ->json('data.id');
        PlanEntitlement::query()->where('key', 'media_storage_mb')->update(['quota' => 0]);

        $this->withHeaders($this->agencyHeaders($agency, ['Idempotency-Key' => 'storage-quota-zero']))
            ->post("/api/v1/listings/{$listing}/media", [
                'file' => UploadedFile::fake()->image('quota.jpg', 1600, 1200),
            ])->assertUnprocessable()->assertJsonPath('error.code', 'MEDIA_STORAGE_QUOTA_EXCEEDED');

        $this->assertDatabaseCount('media', 0);
        $this->assertDatabaseCount('media_derivatives', 0);
        $this->assertSame([], Storage::disk('listing_media')->allFiles());
    }

    /** P0 entitlements AC-8. */
    public function test_registration_flags_deny_before_any_identity_or_tenant_is_persisted(): void
    {
        FeatureFlag::query()->where('key', 'customer_registration')->update(['default_enabled' => false]);
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Blocked Customer',
            'email' => 'blocked-customer@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ])->assertForbidden()->assertJsonPath('error.code', 'FEATURE_DISABLED');

        FeatureFlag::query()->where('key', 'agency_registration')->update(['default_enabled' => false]);
        $this->postJson('/api/v1/auth/register-agency', [
            'name' => 'Blocked Owner',
            'email' => 'blocked-owner@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'agency_name' => 'Blocked Realty',
        ])->assertForbidden()->assertJsonPath('error.code', 'FEATURE_DISABLED');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('agencies', 0);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    /** P0 entitlements AC-9. */
    public function test_messaging_reads_and_writes_are_denied_without_mutation_when_disabled(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        $listing = $this->createPublishedListing($owner, $agency, ['reference' => 'MESSAGE-GATE']);
        $consumer = User::factory()->create();
        Sanctum::actingAs($consumer);
        $lead = $this->postJson("/api/v1/public/listings/{$listing['id']}/leads", $this->inquiryPayload(), [
            'Idempotency-Key' => 'message-gate-lead',
        ])->assertCreated()->json('data');
        PlanEntitlement::query()->where('key', 'messaging')->firstOrFail()->update(['value' => false]);
        $before = \DB::table('messages')->count();

        $this->getJson("/api/v1/conversations/{$lead['conversation_id']}/messages")
            ->assertForbidden()->assertJsonPath('error.code', 'FEATURE_DISABLED');
        $this->postJson("/api/v1/conversations/{$lead['conversation_id']}/messages", ['body' => 'This must not be stored.'])
            ->assertForbidden()->assertJsonPath('error.code', 'FEATURE_DISABLED');
        $this->assertSame($before, \DB::table('messages')->count());
    }

    /** P0 entitlements AC-10. */
    public function test_viewing_routes_are_denied_without_mutation_when_disabled(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        $listing = $this->createPublishedListing($owner, $agency, ['reference' => 'VIEWING-GATE']);
        $consumer = User::factory()->create();
        Sanctum::actingAs($consumer);
        $leadId = $this->postJson("/api/v1/public/listings/{$listing['id']}/leads", $this->inquiryPayload(), [
            'Idempotency-Key' => 'viewing-gate-lead',
        ])->assertCreated()->json('data.id');
        $this->actAsAgencyOwner($owner);
        $startsAt = now()->addDays(2)->setTime(10, 0);
        $viewing = $this->postJson('/api/v1/viewings', [
            'lead_id' => $leadId,
            'starts_at' => $startsAt->toISOString(),
            'ends_at' => $startsAt->copy()->addHour()->toISOString(),
            'timezone' => 'UTC',
        ], $this->agencyHeaders($agency))->assertCreated()->json('data');
        PlanEntitlement::query()->where('key', 'viewings')->firstOrFail()->update(['value' => false]);

        $this->getJson('/api/v1/viewings', $this->agencyHeaders($agency))
            ->assertForbidden()->assertJsonPath('error.code', 'FEATURE_DISABLED');
        $this->postJson('/api/v1/viewings', [
            'lead_id' => $leadId,
            'starts_at' => $startsAt->copy()->addDay()->toISOString(),
            'ends_at' => $startsAt->copy()->addDay()->addHour()->toISOString(),
            'timezone' => 'UTC',
        ], $this->agencyHeaders($agency))->assertForbidden()->assertJsonPath('error.code', 'FEATURE_DISABLED');
        $this->patchJson("/api/v1/viewings/{$viewing['id']}", ['status' => 'confirmed', 'version' => 1], $this->agencyHeaders($agency))
            ->assertForbidden()->assertJsonPath('error.code', 'FEATURE_DISABLED');
        $this->getJson("/api/v1/viewings/{$viewing['id']}/calendar", $this->agencyHeaders($agency))
            ->assertForbidden()->assertJsonPath('error.code', 'FEATURE_DISABLED');
        $this->assertDatabaseCount('viewing_requests', 1);
    }

    /** P0 entitlements AC-11. */
    public function test_reaction_creation_honors_independent_flags_but_cleanup_remains_available(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        $listing = $this->createPublishedListing($owner, $agency, ['reference' => 'REACTION-GATE']);
        Sanctum::actingAs(User::factory()->create());
        FeatureFlag::query()->where('key', 'likes')->update(['default_enabled' => false]);

        $this->putJson("/api/v1/account/reactions/{$listing['id']}", ['reaction' => 'like'])
            ->assertForbidden()->assertJsonPath('error.code', 'FEATURE_DISABLED');
        $this->putJson("/api/v1/account/reactions/{$listing['id']}", ['reaction' => 'dislike'])
            ->assertOk()->assertJsonPath('data.reaction', 'dislike');

        FeatureFlag::query()->where('key', 'dislikes')->update(['default_enabled' => false]);
        $this->deleteJson("/api/v1/account/reactions/{$listing['id']}")
            ->assertOk()->assertJsonPath('data.reaction', null);
        $this->assertDatabaseCount('property_reactions', 0);
    }

    /** P0 entitlements AC-12. */
    public function test_unimplemented_launch_features_are_seeded_disabled(): void
    {
        $this->assertFalse(FeatureFlag::query()->where('key', 'comparisons')->firstOrFail()->default_enabled);
        $this->assertFalse(FeatureFlag::query()->where('key', 'collaborative_collections')->firstOrFail()->default_enabled);
    }

    /** @return array<string, mixed> */
    private function inquiryPayload(): array
    {
        return [
            'name' => 'Jordan Rivera',
            'email' => 'jordan@example.com',
            'phone' => '+1 512 555 0199',
            'message' => 'I would like more information about this property and its availability.',
            'consent' => true,
        ];
    }
}
