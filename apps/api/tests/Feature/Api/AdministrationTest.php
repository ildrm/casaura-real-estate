<?php

namespace Tests\Feature\Api;

use App\Domain\Tenancy\FeatureResolver;
use App\Models\AgencyMember;
use App\Models\FeatureFlag;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesAgencyTenant;
use Tests\TestCase;

class AdministrationTest extends TestCase
{
    use CreatesAgencyTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('listing_media');
    }

    /** Phase 6 AC-1; EC-1. */
    public function test_platform_routes_reject_agency_roles_and_accept_platform_roles_without_tenant_header(): void
    {
        [$owner] = $this->createAgencyOwner();
        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/admin/settings')->assertForbidden();

        $admin = $this->createPlatformOperator('platform_administrator');
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/settings')->assertOk()->assertJsonStructure(['data']);
    }

    /** Phase 6 AC-2, AC-3; EC-2, EC-3, EC-4. */
    public function test_reports_are_idempotent_and_moderation_transitions_are_audited(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        $listing = $this->createPublishedListing($owner, $agency, ['reference' => 'REPORT-LISTING']);
        $reporter = User::factory()->create();
        Sanctum::actingAs($reporter);
        $payload = ['category' => 'misleading', 'details' => 'The advertised information appears inconsistent.'];
        $first = $this->postJson("/api/v1/public/listings/{$listing['id']}/reports", $payload, ['Idempotency-Key' => 'report-one'])->assertCreated();
        $second = $this->postJson("/api/v1/public/listings/{$listing['id']}/reports", $payload, ['Idempotency-Key' => 'report-one'])->assertOk();
        $this->assertSame($first->json('data.case_id'), $second->json('data.case_id'));
        $this->postJson("/api/v1/public/listings/{$listing['id']}/reports", [...$payload, 'category' => 'fraud'], ['Idempotency-Key' => 'report-one'])
            ->assertConflict();
        $this->postJson('/api/v1/public/listings/00000000-0000-0000-0000-000000000000/reports', $payload, ['Idempotency-Key' => 'missing-report'])->assertNotFound();

        $moderator = $this->createPlatformOperator('moderator');
        Sanctum::actingAs($moderator);
        $caseId = $first->json('data.case_id');
        $this->getJson('/api/v1/admin/moderation-cases')->assertOk()
            ->assertJsonPath('data.0.report.details', $payload['details']);
        $this->patchJson("/api/v1/admin/moderation-cases/{$caseId}", [
            'status' => 'reviewing', 'assigned_user_id' => User::factory()->create()->id, 'version' => 1,
        ])->assertUnprocessable()->assertJsonPath('error.code', 'MODERATION_ASSIGNEE_INVALID');
        $this->patchJson("/api/v1/admin/moderation-cases/{$caseId}", [
            'status' => 'reviewing', 'version' => 1,
        ])->assertOk()->assertJsonPath('data.assigned_user_id', $moderator->id);
        $this->patchJson("/api/v1/admin/moderation-cases/{$caseId}", ['status' => 'open', 'version' => 2])
            ->assertConflict()->assertJsonPath('error.code', 'MODERATION_TRANSITION_INVALID');
        $this->patchJson("/api/v1/admin/moderation-cases/{$caseId}", ['status' => 'resolved', 'outcome' => 'listing_corrected', 'note' => 'Agency supplied corrected evidence.', 'version' => 2])
            ->assertOk()->assertJsonPath('data.status', 'resolved');
        $this->assertDatabaseCount('abuse_reports', 1);
        $this->assertDatabaseCount('moderation_case_history', 3);
        $this->assertDatabaseHas('moderation_case_history', ['moderation_case_id' => $caseId, 'assigned_user_id' => $moderator->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'moderation.case_updated']);
    }

    /** Phase 6 AC-4; EC-5. */
    public function test_settings_are_versioned_and_secret_values_are_redacted(): void
    {
        Setting::query()->create(['namespace' => 'integrations', 'key' => 'api_token', 'value' => 'super-secret', 'is_secret' => true]);
        $admin = $this->createPlatformOperator('platform_administrator');
        Sanctum::actingAs($admin);
        $content = $this->getJson('/api/v1/admin/settings')->assertOk()->getContent();
        $this->assertStringNotContainsString('super-secret', $content);
        $this->patchJson('/api/v1/admin/settings/integrations/api_token', ['value' => 'changed', 'version' => 1])
            ->assertUnprocessable()->assertJsonPath('error.code', 'SECRET_SETTING_MANAGED_EXTERNALLY');
        $this->patchJson('/api/v1/admin/settings/billing/default_promotional_days', ['value' => 'forever', 'version' => 1])
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->patchJson('/api/v1/admin/settings/billing/default_promotional_days', ['value' => 180, 'version' => 1])
            ->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson('/api/v1/admin/settings/billing/default_promotional_days', ['value' => 90, 'version' => 1])
            ->assertConflict()->assertJsonPath('error.code', 'SETTING_VERSION_CONFLICT');
    }

    /** Phase 6 AC-5; EC-6. */
    public function test_feature_flag_overrides_are_validated_and_audited(): void
    {
        [, $agency] = $this->createAgencyOwner();
        $admin = $this->createPlatformOperator('platform_administrator');
        Sanctum::actingAs($admin);
        $flagsPage = $this->getJson('/api/v1/admin/feature-flags?limit=1')->assertOk()->assertJsonCount(1, 'data');
        $this->assertNotNull($flagsPage->json('meta.next_cursor'));
        $flag = FeatureFlag::query()->where('key', 'newsletters')->firstOrFail();
        $this->putJson("/api/v1/admin/feature-flags/{$flag->id}/overrides", [
            'scope_type' => 'agency', 'scope_id' => $agency->id, 'enabled' => true,
            'starts_at' => now()->subMinute()->toISOString(), 'ends_at' => now()->addDay()->toISOString(),
        ])->assertOk()->assertJsonPath('data.enabled', true);
        $this->assertDatabaseHas('audit_logs', ['action' => 'feature_flag.override_updated']);
        $this->putJson("/api/v1/admin/feature-flags/{$flag->id}/overrides", [
            'scope_type' => 'agency', 'scope_id' => $agency->id, 'enabled' => true,
            'starts_at' => now()->addDay()->toISOString(), 'ends_at' => now()->subDay()->toISOString(),
        ])->assertUnprocessable();

        $globalFlag = FeatureFlag::query()->where('key', 'comments')->firstOrFail();
        $this->assertFalse(app(FeatureResolver::class)->resolve('comments', $agency)['enabled']);
        $this->putJson("/api/v1/admin/feature-flags/{$globalFlag->id}/overrides", [
            'scope_type' => 'global', 'enabled' => true,
        ])->assertOk()->assertJsonPath('data.scope_id', null);
        $this->assertTrue(app(FeatureResolver::class)->resolve('comments', $agency)['enabled']);
    }

    /** Phase 6 AC-6, AC-7; EC-7, EC-8. */
    public function test_rbac_editor_mutates_only_safe_custom_roles(): void
    {
        $admin = $this->createPlatformOperator('platform_administrator');
        Sanctum::actingAs($admin);
        $rolesPage = $this->getJson('/api/v1/admin/roles?limit=1')->assertOk()->assertJsonCount(1, 'data.roles');
        $this->assertNotNull($rolesPage->json('meta.next_cursor'));
        $role = $this->postJson('/api/v1/admin/roles', [
            'name' => 'Agency coordinator', 'slug' => 'agency_coordinator', 'scope' => 'agency',
            'permissions' => ['listing.view', 'lead.manage'],
        ])->assertCreated()->json('data');
        $this->patchJson("/api/v1/admin/roles/{$role['id']}", ['name' => 'Agency operations coordinator', 'permissions' => ['listing.view', 'analytics.view']])
            ->assertOk()->assertJsonPath('data.name', 'Agency operations coordinator');
        $this->patchJson("/api/v1/admin/roles/{$role['id']}", ['permissions' => ['platform.settings']])
            ->assertUnprocessable()->assertJsonPath('error.code', 'ROLE_PERMISSION_INVALID');
        $system = Role::query()->where('slug', 'agency_owner')->firstOrFail();
        $this->patchJson("/api/v1/admin/roles/{$system->id}", ['name' => 'Changed'])->assertConflict();
        $this->deleteJson("/api/v1/admin/roles/{$system->id}")->assertConflict()->assertJsonPath('error.code', 'SYSTEM_ROLE_IMMUTABLE');
    }

    /** Phase 6 AC-8, AC-9; EC-9. */
    public function test_admin_audit_and_health_projections_are_redacted_and_operational(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        $this->actAsAgencyOwner($owner);
        $this->patchJson('/api/v1/agency', ['short_description' => 'Updated agency summary.'], $this->agencyHeaders($agency))->assertOk();
        $admin = $this->createPlatformOperator('platform_administrator');
        Sanctum::actingAs($admin);
        $audit = $this->getJson('/api/v1/admin/audit-logs?action=agency.profile_updated')->assertOk();
        $audit->assertJsonPath('data.0.action', 'agency.profile_updated');
        $this->assertStringNotContainsString('password', $audit->getContent());
        $this->patchJson('/api/v1/admin/audit-logs/'.$audit->json('data.0.id'), [])->assertNotFound();

        $health = $this->getJson('/api/v1/admin/health')->assertOk()
            ->assertJsonPath('data.components.database.status', 'ok')
            ->assertJsonStructure(['data' => ['status', 'version', 'checked_at', 'components', 'request_id']]);
        $this->assertStringNotContainsString('DB_PASSWORD', $health->getContent());
        $this->assertStringNotContainsString('sqlite', $health->getContent());
    }

    private function createPlatformOperator(string $roleSlug): User
    {
        [$user, $agency] = $this->createAgencyOwner('Platform Operations '.str()->random(5));
        $membership = AgencyMember::query()->where('agency_id', $agency->id)->where('user_id', $user->id)->firstOrFail();
        $membership->roles()->sync([Role::query()->where('slug', $roleSlug)->firstOrFail()->id]);

        return $user;
    }
}
