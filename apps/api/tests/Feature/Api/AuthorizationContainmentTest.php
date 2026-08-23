<?php

namespace Tests\Feature\Api;

use App\Models\AgencyMember;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesAgencyTenant;
use Tests\TestCase;

class AuthorizationContainmentTest extends TestCase
{
    use CreatesAgencyTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /** P0 authorization AC-1, AC-4, AC-5; EC-5, EC-6, EC-7. */
    public function test_platform_authority_requires_active_membership_agency_and_trusted_system_role(): void
    {
        [$user, $agency] = $this->createAgencyOwner();
        $membership = AgencyMember::query()->where('agency_id', $agency->id)->where('user_id', $user->id)->firstOrFail();
        $membership->roles()->sync([Role::query()->where('slug', 'platform_administrator')->firstOrFail()->id]);

        Sanctum::actingAs($user, ['*', 'mfa']);
        $this->getJson('/api/v1/admin/settings')->assertOk();

        $membership->update(['status' => 'inactive']);
        $this->getJson('/api/v1/admin/settings')->assertForbidden()
            ->assertJsonPath('error.code', 'PLATFORM_PERMISSION_DENIED');

        $membership->update(['status' => 'active']);
        $agency->update(['status' => 'inactive']);
        $this->getJson('/api/v1/admin/settings')->assertForbidden()
            ->assertJsonPath('error.code', 'PLATFORM_PERMISSION_DENIED');

        $agency->update(['status' => 'active']);
        $role = Role::query()->create([
            'scope' => 'agency', 'name' => 'Untrusted settings role',
            'slug' => 'untrusted_settings_role', 'is_system' => false,
        ]);
        $role->permissions()->sync([Permission::query()->where('name', 'platform.settings')->firstOrFail()->id]);
        $membership->roles()->sync([$role->id]);
        $this->getJson('/api/v1/admin/settings')->assertForbidden()
            ->assertJsonPath('error.code', 'PLATFORM_PERMISSION_DENIED');
    }

    /** P0 authorization AC-2, AC-3; EC-1, EC-2, EC-3, EC-4. */
    public function test_custom_roles_cannot_impersonate_any_privileged_platform_slug(): void
    {
        foreach ([
            ['moderator', 'comment.moderate', '/api/v1/admin/moderation-cases'],
            ['support_administrator', 'audit.view', '/api/v1/admin/audit-logs'],
            ['platform_administrator', 'platform.settings', '/api/v1/admin/settings'],
            ['super_administrator', 'platform.settings', '/api/v1/admin/settings'],
        ] as [$slug, $permission, $path]) {
            [$user, $agency] = $this->createAgencyOwner('Collision '.str()->random(8));
            $membership = AgencyMember::query()->where('agency_id', $agency->id)->where('user_id', $user->id)->firstOrFail();
            $role = Role::query()->create([
                'scope' => 'agency', 'name' => 'Untrusted '.str($slug)->headline(),
                'slug' => $slug, 'is_system' => false,
            ]);
            $role->permissions()->sync([Permission::query()->where('name', $permission)->firstOrFail()->id]);
            $membership->roles()->sync([$role->id]);

            Sanctum::actingAs($user, ['*', 'mfa']);
            $this->getJson($path)->assertForbidden()
                ->assertJsonPath('error.code', 'PLATFORM_PERMISSION_DENIED');
        }
    }

    /** P0 authorization AC-6, AC-7. */
    public function test_suspended_authenticated_user_is_denied_on_account_tenant_and_admin_routes(): void
    {
        [$user, $agency] = $this->createAgencyOwner();
        $membership = AgencyMember::query()->where('agency_id', $agency->id)->where('user_id', $user->id)->firstOrFail();
        $membership->roles()->sync([Role::query()->where('slug', 'platform_administrator')->firstOrFail()->id]);
        Sanctum::actingAs($user, ['*', 'mfa']);
        $user->update(['status' => 'suspended', 'suspended_at' => now()]);

        foreach ([
            ['/api/v1/me', []],
            ['/api/v1/agency', $this->agencyHeaders($agency)],
            ['/api/v1/admin/settings', []],
        ] as [$path, $headers]) {
            $this->getJson($path, $headers)->assertForbidden()
                ->assertJsonPath('error.code', 'ACCOUNT_ACCESS_DENIED')
                ->assertJsonStructure(['error' => ['request_id']]);
        }
    }

    /** P0 authorization AC-8, AC-9. */
    public function test_unauthenticated_and_active_user_behavior_remains_compatible(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        [$user, $agency] = $this->createAgencyOwner();
        Sanctum::actingAs($user, ['*', 'mfa']);
        $this->getJson('/api/v1/me')->assertOk()->assertJsonStructure(['data' => ['id', 'email']]);
        $this->getJson('/api/v1/agency', $this->agencyHeaders($agency))->assertOk()
            ->assertJsonPath('data.id', $agency->id);
    }
}
