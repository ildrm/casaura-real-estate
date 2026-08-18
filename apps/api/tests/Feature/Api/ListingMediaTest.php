<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\CreatesAgencyTenant;
use Tests\TestCase;

class ListingMediaTest extends TestCase
{
    use CreatesAgencyTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('listing_media');
    }

    public function test_valid_image_is_private_derived_and_idempotent(): void
    {
        [$user, $agency] = $this->createAgencyOwner();
        $this->actAsAgencyOwner($user);
        $listingId = $this->postJson('/api/v1/listings', $this->validListingPayload(), $this->agencyHeaders($agency))
            ->assertCreated()->json('data.id');
        $headers = $this->agencyHeaders($agency, ['Idempotency-Key' => 'upload-oakridge-1']);

        $first = $this->withHeaders($headers)->post("/api/v1/listings/{$listingId}/media", [
            'file' => UploadedFile::fake()->image('oakridge.jpg', 1800, 1200),
            'alt_text' => 'Front elevation of the Oakridge home',
        ]);

        $first->assertCreated()
            ->assertJsonPath('data.mime_type', 'image/jpeg')
            ->assertJsonPath('data.width', 1800)
            ->assertJsonPath('data.height', 1200)
            ->assertJsonMissingPath('data.storage_key');
        $this->assertDatabaseHas('media_derivatives', ['media_id' => $first->json('data.id'), 'kind' => 'thumbnail']);
        $this->assertDatabaseHas('media_derivatives', ['media_id' => $first->json('data.id'), 'kind' => 'display']);
        $this->assertCount(3, Storage::disk('listing_media')->allFiles());

        $this->withHeaders($headers)->post("/api/v1/listings/{$listingId}/media", [
            'file' => UploadedFile::fake()->image('different.jpg'),
        ])->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertDatabaseCount('media', 1);
        $this->assertCount(3, Storage::disk('listing_media')->allFiles());
    }

    public function test_unsupported_or_corrupt_media_is_rejected_without_residue(): void
    {
        [$user, $agency] = $this->createAgencyOwner();
        $this->actAsAgencyOwner($user);
        $listingId = $this->postJson('/api/v1/listings', $this->validListingPayload(), $this->agencyHeaders($agency))
            ->assertCreated()->json('data.id');

        $this->withHeaders($this->agencyHeaders($agency, ['Idempotency-Key' => 'unsafe-1']))
            ->post("/api/v1/listings/{$listingId}/media", [
                'file' => UploadedFile::fake()->createWithContent('payload.jpg', 'not an image'),
            ])->assertUnprocessable()->assertJsonPath('error.code', 'MEDIA_INVALID');

        $this->assertDatabaseCount('media', 0);
        $this->assertDatabaseCount('media_derivatives', 0);
        $this->assertSame([], Storage::disk('listing_media')->allFiles());
    }

    public function test_media_quota_reorder_and_soft_delete_are_enforced(): void
    {
        [$user, $agency] = $this->createAgencyOwner();
        $this->actAsAgencyOwner($user);
        $listingId = $this->postJson('/api/v1/listings', $this->validListingPayload(), $this->agencyHeaders($agency))
            ->assertCreated()->json('data.id');

        $mediaIds = [];
        foreach (range(1, 30) as $position) {
            $mediaIds[] = $id = (string) Str::uuid();
            DB::table('media')->insert([
                'id' => $id,
                'agency_id' => $agency->id,
                'listing_id' => $listingId,
                'idempotency_key' => "seed-{$position}",
                'original_name' => "home-{$position}.jpg",
                'mime_type' => 'image/jpeg',
                'byte_size' => 1024,
                'width' => 100,
                'height' => 100,
                'position' => $position,
                'checksum_sha256' => hash('sha256', "home-{$position}"),
                'storage_key' => "seed/{$position}.jpg",
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Storage::disk('listing_media')->put("seed/{$position}.jpg", 'private-test-image');
        }

        $this->withHeaders($this->agencyHeaders($agency, ['Idempotency-Key' => 'over-quota']))
            ->post("/api/v1/listings/{$listingId}/media", ['file' => UploadedFile::fake()->image('extra.jpg')])
            ->assertUnprocessable()->assertJsonPath('error.code', 'MEDIA_QUOTA_EXCEEDED');

        $reversed = array_reverse($mediaIds);
        $this->patchJson("/api/v1/listings/{$listingId}/media/order", ['media_ids' => $reversed], $this->agencyHeaders($agency))
            ->assertOk()->assertJsonPath('data.0.id', $reversed[0]);

        $this->deleteJson("/api/v1/listings/{$listingId}/media/{$reversed[0]}", [], $this->agencyHeaders($agency))->assertNoContent();
        $this->assertSoftDeleted('media', ['id' => $reversed[0]]);
        $this->assertDatabaseHas('listing_versions', ['listing_id' => $listingId, 'version' => 3]);
    }
}
