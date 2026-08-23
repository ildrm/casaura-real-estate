<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesAgencyTenant;
use Tests\TestCase;

class ListingWorkflowTest extends TestCase
{
    use CreatesAgencyTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('listing_media');
    }

    public function test_create_draft_separates_property_and_listing_and_appends_initial_history(): void
    {
        [$user, $agency] = $this->createAgencyOwner();
        $this->actAsAgencyOwner($user);

        $response = $this->postJson('/api/v1/listings', $this->validListingPayload(), $this->agencyHeaders($agency));

        $response->assertCreated()
            ->assertJsonPath('data.agency_id', $agency->id)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.version', 1)
            ->assertJsonStructure(['data' => ['id', 'property_id', 'quality' => ['score', 'ready_for_review', 'checklist']]]);

        $listingId = $response->json('data.id');
        $this->assertDatabaseHas('properties', ['id' => $response->json('data.property_id'), 'agency_id' => $agency->id]);
        $this->assertDatabaseHas('listings', ['id' => $listingId, 'agency_id' => $agency->id, 'status' => 'draft']);
        $this->assertDatabaseHas('listing_versions', ['listing_id' => $listingId, 'version' => 1]);
        $this->assertDatabaseHas('listing_status_history', ['listing_id' => $listingId, 'to_status' => 'draft']);
        $this->assertDatabaseHas('price_history', ['listing_id' => $listingId, 'amount_minor' => 139500000]);
        $this->assertDatabaseHas('audit_logs', ['agency_id' => $agency->id, 'action' => 'listing.created']);
    }

    public function test_catalogue_and_filtered_inventory_are_tenant_safe(): void
    {
        [$userA, $agencyA] = $this->createAgencyOwner('Agency A');
        [$userB, $agencyB] = $this->createAgencyOwner('Agency B');
        $this->actAsAgencyOwner($userA);

        $catalogue = $this->getJson('/api/v1/property-catalog', $this->agencyHeaders($agencyA));
        $catalogue->assertOk()
            ->assertJsonFragment(['slug' => 'house'])
            ->assertJsonFragment(['slug' => 'garden'])
            ->assertJsonFragment(['slug' => 'year_built']);

        $listingA = $this->postJson('/api/v1/listings', $this->validListingPayload([
            'reference' => 'A-MAPLE',
            'title' => 'Maple Street family home',
        ]), $this->agencyHeaders($agencyA))->assertCreated()->json('data.id');

        $this->actAsAgencyOwner($userB);
        $this->postJson('/api/v1/listings', $this->validListingPayload(['reference' => 'B-MAPLE']), $this->agencyHeaders($agencyB))->assertCreated();

        $this->getJson('/api/v1/listings?status=draft&q=Maple&limit=20', $this->agencyHeaders($agencyB))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonMissing(['id' => $listingA])
            ->assertJsonStructure(['data', 'meta' => ['next_cursor']]);

        foreach (['get', 'patch', 'delete'] as $method) {
            $response = match ($method) {
                'get' => $this->getJson("/api/v1/listings/{$listingA}", $this->agencyHeaders($agencyB)),
                'patch' => $this->patchJson("/api/v1/listings/{$listingA}", ['version' => 1, 'title' => 'Cross tenant'], $this->agencyHeaders($agencyB)),
                'delete' => $this->deleteJson("/api/v1/listings/{$listingA}", [], $this->agencyHeaders($agencyB)),
            };
            $response->assertNotFound();
        }
    }

    public function test_current_autosave_updates_features_history_and_rejects_a_stale_version(): void
    {
        [$user, $agency] = $this->createAgencyOwner();
        $this->actAsAgencyOwner($user);
        $listingId = $this->postJson('/api/v1/listings', $this->validListingPayload(), $this->agencyHeaders($agency))
            ->assertCreated()->json('data.id');

        $update = $this->patchJson("/api/v1/listings/{$listingId}", [
            'version' => 1,
            'title' => 'Updated Oakridge family home',
            'price' => ['amount_minor' => 142000000, 'currency' => 'USD'],
            'features' => ['year_built' => 2019, 'parking_spaces' => 3],
            'amenity_slugs' => ['garden', 'garage', 'pool'],
        ], $this->agencyHeaders($agency));

        $update->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.property.features.year_built', 2019)
            ->assertJsonPath('data.property.amenities.2', 'pool');
        $this->assertDatabaseHas('listing_versions', ['listing_id' => $listingId, 'version' => 2]);
        $this->assertDatabaseHas('price_history', ['listing_id' => $listingId, 'amount_minor' => 142000000]);

        $this->patchJson("/api/v1/listings/{$listingId}", ['version' => 1, 'title' => 'Stale'], $this->agencyHeaders($agency))
            ->assertConflict()
            ->assertJsonPath('error.code', 'LISTING_VERSION_CONFLICT')
            ->assertJsonPath('error.current_version', 2);
        $this->assertDatabaseMissing('listings', ['id' => $listingId, 'title' => 'Stale']);
    }

    public function test_incomplete_listing_cannot_submit_but_complete_listing_can_be_reviewed_and_published(): void
    {
        [$user, $agency] = $this->createAgencyOwner();
        $this->actAsAgencyOwner($user);

        $incompleteId = $this->postJson('/api/v1/listings', [
            'reference' => 'GR-INCOMPLETE',
            'intent' => 'sale',
            'property_type_slug' => 'house',
        ], $this->agencyHeaders($agency))->assertCreated()->json('data.id');

        $this->postJson("/api/v1/listings/{$incompleteId}/submit", [], $this->agencyHeaders($agency))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'LISTING_NOT_READY')
            ->assertJsonStructure(['error' => ['checklist']]);

        $listingId = $this->postJson('/api/v1/listings', $this->validListingPayload(['reference' => 'GR-COMPLETE']), $this->agencyHeaders($agency))
            ->assertCreated()->json('data.id');

        foreach (range(1, 5) as $position) {
            $this->withHeaders($this->agencyHeaders($agency, ['Idempotency-Key' => "complete-{$position}"]))
                ->post("/api/v1/listings/{$listingId}/media", [
                    'file' => UploadedFile::fake()->image("home-{$position}.jpg", 1200, 800),
                    'alt_text' => "Oakridge home view {$position}",
                ])->assertCreated();
        }

        $submitted = $this->postJson("/api/v1/listings/{$listingId}/submit", [], $this->agencyHeaders($agency));
        $submitted->assertOk()->assertJsonPath('data.status', 'in_review');

        $published = $this->postJson("/api/v1/listings/{$listingId}/publish", [], $this->agencyHeaders($agency));
        $published->assertOk()->assertJsonPath('data.status', 'published');
        $this->assertDatabaseHas('listing_status_history', ['listing_id' => $listingId, 'to_status' => 'published']);
        $this->assertDatabaseHas('audit_logs', ['agency_id' => $agency->id, 'action' => 'listing.published']);

        $this->deleteJson("/api/v1/listings/{$listingId}", [], $this->agencyHeaders($agency))
            ->assertConflict()
            ->assertJsonPath('error.code', 'LISTING_WITHDRAWAL_REQUIRED');
    }

    public function test_reviewer_can_request_changes_with_an_immutable_note(): void
    {
        [$user, $agency] = $this->createAgencyOwner();
        $this->actAsAgencyOwner($user);
        $listingId = $this->postJson('/api/v1/listings', $this->validListingPayload(['reference' => 'GR-REVIEW']), $this->agencyHeaders($agency))
            ->assertCreated()->json('data.id');

        foreach (range(1, 5) as $position) {
            $this->withHeaders($this->agencyHeaders($agency, ['Idempotency-Key' => "review-{$position}"]))
                ->post("/api/v1/listings/{$listingId}/media", ['file' => UploadedFile::fake()->image("review-{$position}.jpg")])
                ->assertCreated();
        }
        $this->postJson("/api/v1/listings/{$listingId}/submit", [], $this->agencyHeaders($agency))->assertOk();

        $this->postJson("/api/v1/listings/{$listingId}/request-changes", ['note' => 'Add an exterior boundary photo.'], $this->agencyHeaders($agency))
            ->assertOk()
            ->assertJsonPath('data.status', 'changes_requested');
        $this->assertDatabaseHas('listing_status_history', [
            'listing_id' => $listingId,
            'to_status' => 'changes_requested',
            'note' => 'Add an exterior boundary photo.',
        ]);
    }
}
