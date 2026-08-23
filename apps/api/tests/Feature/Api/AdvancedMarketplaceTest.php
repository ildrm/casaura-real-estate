<?php

namespace Tests\Feature\Api;

use App\Models\FeatureFlag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesAgencyTenant;
use Tests\TestCase;

class AdvancedMarketplaceTest extends TestCase
{
    use CreatesAgencyTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('listing_media');
        FeatureFlag::query()->whereIn('key', ['comparisons', 'collaborative_collections'])
            ->update(['default_enabled' => true]);
    }

    /** Phase 8 AC-1 through AC-3. */
    public function test_collections_enforce_membership_roles_order_and_cross_account_privacy(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        $listing = $this->createPublishedListing($owner, $agency, ['reference' => 'COLLECTION-ONE']);
        $consumer = User::factory()->create();
        Sanctum::actingAs($consumer, ['*', 'mfa']);

        $collection = $this->postJson('/api/v1/account/collections', ['name' => 'Austin shortlist'])
            ->assertCreated()->json('data');
        $this->putJson("/api/v1/account/collections/{$collection['id']}/items", [
            'listing_id' => $listing['id'],
        ])->assertOk()->assertJsonCount(1, 'data.items');
        $this->putJson("/api/v1/account/collections/{$collection['id']}/items", [
            'listing_id' => $listing['id'],
        ])->assertOk()->assertJsonCount(1, 'data.items');

        $stranger = User::factory()->create();
        Sanctum::actingAs($stranger, ['*', 'mfa']);
        $this->getJson("/api/v1/account/collections/{$collection['id']}")->assertNotFound();
        $this->patchJson("/api/v1/account/collections/{$collection['id']}", [
            'name' => 'Stolen', 'version' => 2,
        ])->assertNotFound();
    }

    /** Phase 8 AC-4 through AC-8. */
    public function test_comparison_recommendations_layers_and_market_analytics_use_public_facts(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        $subject = $this->createPublishedListing($owner, $agency, ['reference' => 'COMPARE-ONE']);
        $similar = $this->createPublishedListing($owner, $agency, [
            'reference' => 'COMPARE-TWO',
            'price' => ['amount_minor' => 145000000, 'currency' => 'USD'],
        ]);

        $comparison = $this->getJson('/api/v1/public/compare?ids='.$subject['id'].','.$similar['id'])
            ->assertOk()->json('data');
        $this->assertCount(2, $comparison['items']);
        $this->assertArrayNotHasKey('line_1', $comparison['items'][0]['location']);

        $recommendations = $this->getJson("/api/v1/public/listings/{$subject['id']}/recommendations")
            ->assertOk()->json('data');
        $this->assertSame($similar['id'], $recommendations[0]['listing']['id']);
        $this->assertNotEmpty($recommendations[0]['reasons']);

        DB::table('search_documents')->whereIn('listing_id', [$subject['id'], $similar['id']])->update([
            'public_latitude' => 30.2672,
            'public_longitude' => -97.7431,
            'location_policy' => 'approximate',
        ]);
        $this->getJson('/api/v1/public/map-layers?layer=density&bounds=-98,30,-97,31')
            ->assertOk()->assertJsonCount(0, 'data.buckets');

        $sparse = $this->getJson('/api/v1/public/market-analytics?locality=Austin&from=2026-01-01&to=2026-12-31')
            ->assertOk()->json('data');
        $this->assertFalse($sparse['sufficient_cohort']);
        $this->assertNull($sparse['median_price_minor']);
    }

    /** Phase 8 AC-5. */
    public function test_comparison_history_is_private_and_idempotent(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        $first = $this->createPublishedListing($owner, $agency, ['reference' => 'HISTORY-ONE']);
        $second = $this->createPublishedListing($owner, $agency, ['reference' => 'HISTORY-TWO']);
        $consumer = User::factory()->create();
        Sanctum::actingAs($consumer, ['*', 'mfa']);
        $payload = ['listing_ids' => [$first['id'], $second['id']]];

        $saved = $this->postJson('/api/v1/account/comparisons', $payload)->assertCreated()->json('data');
        $this->postJson('/api/v1/account/comparisons', $payload)->assertOk()
            ->assertJsonPath('data.id', $saved['id']);
        $this->getJson('/api/v1/account/comparisons')->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson("/api/v1/account/comparisons/{$saved['id']}")->assertNoContent();
        $this->assertDatabaseCount('comparison_histories', 0);
    }
}
