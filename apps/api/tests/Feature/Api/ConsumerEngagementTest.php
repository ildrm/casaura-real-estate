<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesAgencyTenant;
use Tests\TestCase;

class ConsumerEngagementTest extends TestCase
{
    use CreatesAgencyTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('listing_media');
    }

    public function test_consumer_favorites_are_idempotent_and_private_to_the_account(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        $published = $this->createPublishedListing($owner, $agency, ['reference' => 'FAVORITE-ME']);
        $consumer = User::factory()->create();
        Sanctum::actingAs($consumer);

        foreach (range(1, 2) as $attempt) {
            $this->putJson("/api/v1/account/favorites/{$published['id']}")
                ->assertOk()->assertJsonPath('data.favorite', true);
        }
        $this->assertDatabaseCount('favorites', 1);
        $this->getJson('/api/v1/account/engagements')
            ->assertOk()
            ->assertJsonPath('data.favorites.0.id', $published['id']);

        foreach (range(1, 2) as $attempt) {
            $this->deleteJson("/api/v1/account/favorites/{$published['id']}")
                ->assertOk()->assertJsonPath('data.favorite', false);
        }
        $this->assertDatabaseCount('favorites', 0);
    }

    public function test_consumer_can_replace_and_remove_a_private_reaction(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        $published = $this->createPublishedListing($owner, $agency, ['reference' => 'REACT-ME']);
        $consumer = User::factory()->create();
        Sanctum::actingAs($consumer);

        $this->putJson("/api/v1/account/reactions/{$published['id']}", ['reaction' => 'like'])
            ->assertOk()->assertJsonPath('data.reaction', 'like');
        $this->putJson("/api/v1/account/reactions/{$published['id']}", ['reaction' => 'dislike'])
            ->assertOk()->assertJsonPath('data.reaction', 'dislike');
        $this->assertDatabaseCount('property_reactions', 1);
        $this->assertDatabaseHas('property_reactions', ['user_id' => $consumer->id, 'listing_id' => $published['id'], 'reaction' => 'dislike']);

        $this->getJson('/api/v1/account/engagements')
            ->assertOk()
            ->assertJsonPath('data.dislikes.0.id', $published['id'])
            ->assertJsonCount(0, 'data.likes');
        $this->deleteJson("/api/v1/account/reactions/{$published['id']}")
            ->assertOk()->assertJsonPath('data.reaction', null);
        $this->assertDatabaseCount('property_reactions', 0);

        $public = $this->getJson("/api/v1/public/listings/{$published['id']}")->assertOk()->getContent();
        $this->assertStringNotContainsString('reaction_count', $public);
        $this->assertStringNotContainsString('dislike', $public);
    }

    public function test_unpublished_listings_cannot_receive_engagement(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        $this->actAsAgencyOwner($owner);
        $draft = $this->postJson('/api/v1/listings', $this->validListingPayload(['reference' => 'NO-ENGAGEMENT']), $this->agencyHeaders($agency))
            ->assertCreated()->json('data.id');
        Sanctum::actingAs(User::factory()->create());

        $this->putJson("/api/v1/account/favorites/{$draft}")->assertNotFound();
        $this->putJson("/api/v1/account/reactions/{$draft}", ['reaction' => 'like'])->assertNotFound();
        $this->assertDatabaseCount('favorites', 0);
        $this->assertDatabaseCount('property_reactions', 0);
    }

    public function test_engagement_requires_authentication(): void
    {
        $this->getJson('/api/v1/account/engagements')->assertUnauthorized();
        $this->putJson('/api/v1/account/favorites/00000000-0000-0000-0000-000000000000')->assertUnauthorized();
    }
}
