<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesAgencyTenant;
use Tests\TestCase;

class PublicMarketplaceTest extends TestCase
{
    use CreatesAgencyTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('listing_media');
    }

    public function test_published_listing_is_projected_without_private_fields_and_resolves_public_detail(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        $published = $this->createPublishedListing($owner, $agency, [
            'reference' => 'PUBLIC-OAKRIDGE',
            'address' => [
                'line_1' => '241 Oakridge Drive',
                'locality' => 'Oakridge',
                'region' => 'OR',
                'postal_code' => '97463',
                'country_code' => 'US',
                'latitude' => 43.7485,
                'longitude' => -122.4617,
            ],
        ]);

        $this->assertDatabaseHas('search_documents', [
            'listing_id' => $published['id'],
            'projection_version' => $published['version'],
            'status' => 'published',
        ]);
        $this->assertDatabaseHas('search_projection_outbox', [
            'listing_id' => $published['id'],
            'operation' => 'upsert',
        ]);

        $search = $this->getJson('/api/v1/public/search?q=Oakridge&intent=sale');
        $search->assertOk()
            ->assertJsonPath('data.0.id', $published['id'])
            ->assertJsonPath('data.0.location.policy', 'approximate')
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('meta.applied_filters.q', 'Oakridge');

        $detail = $this->getJson("/api/v1/public/listings/{$published['id']}");
        $detail->assertOk()
            ->assertJsonPath('data.id', $published['id'])
            ->assertJsonPath('data.agency.name', $agency->name)
            ->assertJsonPath('data.location.policy', 'approximate')
            ->assertJsonStructure(['data' => ['slug', 'price_history', 'similar_listings', 'media']]);

        $encoded = $detail->getContent();
        $this->assertStringNotContainsString('241 Oakridge Drive', $encoded);
        $this->assertStringNotContainsString('storage_key', $encoded);
        $this->assertStringNotContainsString('private_latitude', $encoded);
        $this->assertNotSame(43.7485, $detail->json('data.location.latitude'));
        $this->get($detail->json('data.media.0.display_url'))
            ->assertOk()
            ->assertHeader('content-type', 'image/webp');
        $this->getJson('/api/v1/public/discovery')->assertOk()
            ->assertJsonPath('data.listings.0.id', $published['id'])
            ->assertJsonPath('data.agencies.0.slug', $agency->slug);
    }

    public function test_search_applies_hard_filters_bounds_radius_and_excludes_unpublished_inventory(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        $house = $this->createPublishedListing($owner, $agency, [
            'reference' => 'FILTER-HOUSE',
            'title' => 'Oakridge garden house',
            'address' => ['locality' => 'Oakridge', 'region' => 'OR', 'country_code' => 'US', 'latitude' => 43.75, 'longitude' => -122.46],
        ]);
        $this->createPublishedListing($owner, $agency, [
            'reference' => 'FILTER-RENT',
            'intent' => 'rent',
            'property_type_slug' => 'apartment',
            'title' => 'Downtown rental apartment',
            'price' => ['amount_minor' => 320000, 'currency' => 'USD'],
            'bedrooms' => 1,
            'amenity_slugs' => ['balcony'],
            'address' => ['locality' => 'Portland', 'region' => 'OR', 'country_code' => 'US', 'latitude' => 45.52, 'longitude' => -122.68],
        ]);
        $draft = $this->postJson('/api/v1/listings', $this->validListingPayload([
            'reference' => 'PRIVATE-OAKRIDGE',
            'title' => 'Oakridge private draft',
        ]), $this->agencyHeaders($agency))->assertCreated()->json('data.id');

        $query = '/api/v1/public/search?intent=sale&property_type=house&min_bedrooms=3&max_price=150000000&currency=USD&amenities=garden&bounds=-122.6,43.6,-122.3,43.9';
        $this->getJson($query)->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $house['id'])
            ->assertJsonMissing(['id' => $draft]);

        $this->getJson('/api/v1/public/search?radius=43.75,-122.46,10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $house['id']);

        $this->getJson('/api/v1/public/search?radius=43.75,-122.46,-1')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['fields' => ['radius']]]);
        $this->getJson('/api/v1/public/search?bounds=181,43.6,-122.3,43.9')
            ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['bounds']]]);
    }

    public function test_search_uses_stable_keyset_cursors_and_rejects_cursor_reuse_across_sorts(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        $firstListing = $this->createPublishedListing($owner, $agency, ['reference' => 'CURSOR-FIRST']);
        $secondListing = $this->createPublishedListing($owner, $agency, ['reference' => 'CURSOR-SECOND']);

        $firstPage = $this->getJson('/api/v1/public/search?limit=1&sort=newest')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.count', 2);
        $cursor = $firstPage->json('meta.next_cursor');
        $this->assertNotNull($cursor);
        $secondPage = $this->getJson('/api/v1/public/search?limit=1&sort=newest&cursor='.urlencode($cursor))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.count', 2);
        $this->assertNotSame($firstPage->json('data.0.id'), $secondPage->json('data.0.id'));
        $this->assertEqualsCanonicalizing(
            [$firstListing['id'], $secondListing['id']],
            [$firstPage->json('data.0.id'), $secondPage->json('data.0.id')],
        );
        $this->getJson('/api/v1/public/search?limit=1&sort=price_asc&cursor='.urlencode($cursor))
            ->assertUnprocessable()->assertJsonPath('error.code', 'SEARCH_CURSOR_INVALID');
        $this->getJson('/api/v1/public/search?cursor=not-a-cursor')
            ->assertUnprocessable()->assertJsonPath('error.code', 'SEARCH_CURSOR_INVALID');
    }

    public function test_withdrawal_removes_public_projection_but_keeps_canonical_history(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        $published = $this->createPublishedListing($owner, $agency, ['reference' => 'WITHDRAW-ME']);

        $this->postJson("/api/v1/listings/{$published['id']}/withdraw", [], $this->agencyHeaders($agency))
            ->assertOk()->assertJsonPath('data.status', 'withdrawn');

        $this->assertDatabaseMissing('search_documents', ['listing_id' => $published['id']]);
        $this->assertDatabaseHas('listing_status_history', ['listing_id' => $published['id'], 'to_status' => 'withdrawn']);
        $this->getJson("/api/v1/public/listings/{$published['id']}")->assertNotFound();
        $this->getJson('/api/v1/public/search?q=WITHDRAW-ME')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_search_projection_rebuild_is_idempotent(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        $published = $this->createPublishedListing($owner, $agency, ['reference' => 'REBUILD-ME']);
        $this->assertDatabaseHas('search_documents', ['listing_id' => $published['id']]);

        \DB::table('search_documents')->delete();
        $this->assertSame(0, Artisan::call('search:rebuild'));
        $this->assertSame(0, Artisan::call('search:rebuild'));

        $this->assertDatabaseCount('search_documents', 1);
        $this->assertDatabaseHas('search_documents', ['listing_id' => $published['id'], 'projection_version' => $published['version']]);
    }
}
