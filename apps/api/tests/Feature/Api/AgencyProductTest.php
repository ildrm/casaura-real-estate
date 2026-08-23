<?php

namespace Tests\Feature\Api;

use App\Models\AgencyMember;
use App\Models\FeatureFlag;
use App\Models\FeatureFlagOverride;
use App\Models\PlanEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesAgencyTenant;
use Tests\TestCase;

class AgencyProductTest extends TestCase
{
    use CreatesAgencyTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('listing_media');
    }

    /** Phase 5 AC-1, AC-2. */
    public function test_public_storefront_is_feature_gated_and_allowlisted(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        $this->createPublishedListing($owner, $agency, ['reference' => 'STOREFRONT-LISTING']);
        $this->actAsAgencyOwner($owner);
        $this->putJson('/api/v1/agency/opening-hours', ['hours' => $this->validHours()], $this->agencyHeaders($agency))->assertOk();
        AgencyMember::query()->where('agency_id', $agency->id)->where('user_id', $owner->id)
            ->update(['is_public' => true]);

        $content = $this->getJson("/api/v1/public/agencies/{$agency->slug}")->assertOk()
            ->assertJsonPath('data.agency.slug', $agency->slug)
            ->assertJsonCount(7, 'data.opening_hours')
            ->assertJsonCount(1, 'data.team')
            ->assertJsonCount(1, 'data.listings')->getContent();
        $this->assertStringNotContainsString('registration_number', $content);
        $this->assertStringNotContainsString($owner->email, $content);

        FeatureFlagOverride::query()->create([
            'feature_flag_id' => FeatureFlag::query()->where('key', 'agency_storefronts')->firstOrFail()->id,
            'scope_type' => 'agency', 'scope_id' => $agency->id, 'enabled' => false,
        ]);
        $this->getJson("/api/v1/public/agencies/{$agency->slug}")->assertNotFound();
        $this->getJson('/api/v1/public/agencies/not-a-real-agency')->assertNotFound();
    }

    /** Phase 5 AC-3; EC-1. */
    public function test_opening_hours_replace_atomically_and_validate_ranges(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        $this->actAsAgencyOwner($owner);
        $this->putJson('/api/v1/agency/opening-hours', ['hours' => $this->validHours(), 'closures' => [
            ['date' => now()->addMonth()->toDateString(), 'closed' => true, 'reason' => 'Public holiday'],
        ]], $this->agencyHeaders($agency))->assertOk()->assertJsonCount(7, 'data.hours');
        $this->assertDatabaseCount('agency_opening_hours', 7);
        $this->assertDatabaseHas('audit_logs', ['agency_id' => $agency->id, 'action' => 'agency.opening_hours_updated']);

        $invalid = $this->validHours();
        $invalid[1] = ['weekday' => 1, 'opens_at' => '17:00', 'closes_at' => '09:00', 'closed' => false];
        $this->putJson('/api/v1/agency/opening-hours', ['hours' => $invalid], $this->agencyHeaders($agency))->assertUnprocessable();
        $this->assertDatabaseCount('agency_opening_hours', 7);
    }

    /** Phase 5 AC-4, AC-5; EC-2, EC-3. */
    public function test_team_management_enforces_tenant_role_and_quota_boundaries(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        $this->actAsAgencyOwner($owner);
        $member = $this->postJson('/api/v1/agency/team', [
            'name' => 'Casey Morgan', 'email' => 'casey@example.com', 'job_title' => 'Sales agent', 'role_slug' => 'agent',
        ], $this->agencyHeaders($agency))->assertCreated()->json('data');
        $this->assertDatabaseHas('agency_members', ['id' => $member['id'], 'agency_id' => $agency->id]);
        $this->assertDatabaseHas('audit_logs', ['agency_id' => $agency->id, 'action' => 'agency.member_invited']);
        $this->postJson('/api/v1/agency/team', [
            'name' => 'Casey Morgan', 'email' => 'casey@example.com', 'role_slug' => 'agent',
        ], $this->agencyHeaders($agency))->assertConflict()->assertJsonPath('error.code', 'MEMBER_EXISTS');
        $this->patchJson("/api/v1/agency/team/{$member['id']}", ['status' => 'active', 'role_slug' => 'platform_administrator'], $this->agencyHeaders($agency))
            ->assertUnprocessable()->assertJsonPath('error.code', 'TEAM_ROLE_INVALID');

        PlanEntitlement::query()->where('key', 'team_management')->update(['quota' => 2]);
        $this->postJson('/api/v1/agency/team', [
            'name' => 'Second Invite', 'email' => 'second@example.com', 'role_slug' => 'agent',
        ], $this->agencyHeaders($agency))->assertUnprocessable()->assertJsonPath('error.code', 'TEAM_QUOTA_EXCEEDED');
    }

    /** Phase 5 AC-6, AC-7; EC-4, EC-5. */
    public function test_newsletter_subscription_is_gated_idempotent_and_unsubscribable(): void
    {
        [, $agency] = $this->createAgencyOwner();
        $payload = ['email' => 'reader@example.com', 'consent' => true, 'consent_source' => 'storefront'];
        $this->postJson("/api/v1/public/agencies/{$agency->id}/newsletter/subscriptions", $payload, ['Idempotency-Key' => 'newsletter-one'])
            ->assertForbidden()->assertJsonPath('error.code', 'FEATURE_DISABLED');

        FeatureFlag::query()->where('key', 'newsletters')->update(['default_enabled' => true]);
        $first = $this->postJson("/api/v1/public/agencies/{$agency->id}/newsletter/subscriptions", $payload, ['Idempotency-Key' => 'newsletter-one'])->assertCreated();
        $second = $this->postJson("/api/v1/public/agencies/{$agency->id}/newsletter/subscriptions", $payload, ['Idempotency-Key' => 'newsletter-one'])->assertOk();
        $this->assertSame($first->json('data.unsubscribe_token'), $second->json('data.unsubscribe_token'));
        $this->assertDatabaseCount('newsletter_subscribers', 1);
        $this->assertDatabaseHas('audit_logs', ['agency_id' => $agency->id, 'action' => 'newsletter.subscriber_consented']);
        $token = $first->json('data.unsubscribe_token');
        $this->deleteJson("/api/v1/public/newsletter/subscriptions/{$token}")->assertNoContent();
        $this->assertDatabaseHas('audit_logs', ['agency_id' => $agency->id, 'action' => 'newsletter.subscriber_unsubscribed']);
        $this->deleteJson("/api/v1/public/newsletter/subscriptions/{$token}")->assertNoContent();
        $this->deleteJson('/api/v1/public/newsletter/subscriptions/not-a-token')->assertNotFound();
    }

    /** Phase 5 AC-8; EC-6, EC-7, EC-8. */
    public function test_newsletter_campaign_sends_once_through_the_delivery_port(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        FeatureFlag::query()->where('key', 'newsletters')->update(['default_enabled' => true]);
        foreach (['one@example.com', 'two@example.com'] as $index => $email) {
            $this->postJson("/api/v1/public/agencies/{$agency->id}/newsletter/subscriptions", [
                'email' => $email, 'consent' => true, 'consent_source' => 'storefront',
            ], ['Idempotency-Key' => "subscriber-{$index}"])->assertCreated();
        }
        $this->actAsAgencyOwner($owner);
        $this->postJson('/api/v1/agency/newsletter/campaigns', ['subject' => '', 'body' => 'Body'], $this->agencyHeaders($agency))->assertUnprocessable();
        $campaign = $this->postJson('/api/v1/agency/newsletter/campaigns', [
            'subject' => 'August market notes', 'body' => 'A concise update from your local property team.',
        ], $this->agencyHeaders($agency))->assertCreated()->json('data');
        $this->postJson("/api/v1/agency/newsletter/campaigns/{$campaign['id']}/send", [], $this->agencyHeaders($agency))
            ->assertOk()->assertJsonPath('data.delivery_count', 2);
        $this->postJson("/api/v1/agency/newsletter/campaigns/{$campaign['id']}/send", [], $this->agencyHeaders($agency))
            ->assertOk()->assertJsonPath('data.delivery_count', 2);
        $this->assertDatabaseCount('newsletter_events', 2);
    }

    /** Phase 5 AC-9, AC-10. */
    public function test_agency_analytics_are_privacy_safe_and_derived(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        $listing = $this->createPublishedListing($owner, $agency, ['reference' => 'GROWTH-ANALYTICS']);
        $this->getJson("/api/v1/public/agencies/{$agency->slug}")->assertOk();
        $this->getJson("/api/v1/public/agencies/{$agency->slug}")->assertOk();
        $this->getJson("/api/v1/public/listings/{$listing['id']}")->assertOk();
        $this->getJson("/api/v1/public/listings/{$listing['id']}")->assertOk();
        $this->actAsAgencyOwner($owner);
        $this->getJson('/api/v1/agency/analytics?from='.now()->subDays(7)->toDateString().'&to='.now()->toDateString(), $this->agencyHeaders($agency))
            ->assertOk()->assertJsonPath('data.storefront_views', 1)->assertJsonPath('data.listing_views', 1)
            ->assertJsonPath('data.leads', 0);
        $eventJson = json_encode(\DB::table('analytics_events')->get(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($owner->email, $eventJson);
        $this->assertStringNotContainsString('unsubscribe', $eventJson);
        $this->getJson('/api/v1/agency/analytics?from='.now()->subDays(367)->toDateString().'&to='.now()->toDateString(), $this->agencyHeaders($agency))->assertUnprocessable();
    }

    /** @return list<array<string, mixed>> */
    private function validHours(): array
    {
        return collect(range(0, 6))->map(fn (int $weekday) => [
            'weekday' => $weekday, 'opens_at' => $weekday === 0 ? null : '09:00',
            'closes_at' => $weekday === 0 ? null : '17:30', 'closed' => $weekday === 0,
        ])->all();
    }
}
