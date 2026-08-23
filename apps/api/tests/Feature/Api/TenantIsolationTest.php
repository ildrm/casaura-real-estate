<?php

namespace Tests\Feature\Api;

use App\Models\Agency;
use App\Models\AgencyMember;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_cannot_select_another_agency_as_the_active_tenant(): void
    {
        $this->seed();
        [$userA, $agencyA] = $this->createAgencyWithOwner('Agency A');
        [, $agencyB] = $this->createAgencyWithOwner('Agency B');
        Sanctum::actingAs($userA, ['*', 'mfa']);

        $this->getJson('/api/v1/agency', ['Agency-ID' => $agencyB->id])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'TENANT_ACCESS_DENIED');

        $this->getJson('/api/v1/agency', ['Agency-ID' => $agencyA->id])
            ->assertOk()
            ->assertJsonPath('data.id', $agencyA->id);
    }

    public function test_member_listing_is_scoped_to_the_selected_agency(): void
    {
        $this->seed();
        [$userA, $agencyA] = $this->createAgencyWithOwner('Agency A');
        [$userB, $agencyB] = $this->createAgencyWithOwner('Agency B');
        Sanctum::actingAs($userA, ['*', 'mfa']);

        $response = $this->getJson('/api/v1/agency/members', ['Agency-ID' => $agencyA->id]);

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.id', $userA->id)
            ->assertJsonMissing(['id' => $userB->id])
            ->assertJsonMissing(['id' => $agencyB->id]);
    }

    public function test_tenant_header_is_mandatory_on_private_agency_routes(): void
    {
        $this->seed();
        [$user] = $this->createAgencyWithOwner('Agency A');
        Sanctum::actingAs($user, ['*', 'mfa']);

        $this->getJson('/api/v1/agency')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'TENANT_REQUIRED');
    }

    /** @return array{User, Agency} */
    private function createAgencyWithOwner(string $name): array
    {
        $user = User::factory()->create();
        $user->forceFill([
            'mfa_secret' => 'JBSWY3DPEHPK3PXP',
            'mfa_confirmed_at' => now(),
        ])->save();
        $agency = Agency::query()->create([
            'owner_user_id' => $user->id,
            'name' => $name,
            'slug' => str($name)->slug().'-'.str()->lower(str()->random(5)),
            'email' => $user->email,
        ]);
        $membership = AgencyMember::query()->create([
            'agency_id' => $agency->id,
            'user_id' => $user->id,
            'status' => 'active',
            'accepted_at' => now(),
        ]);
        $membership->roles()->attach(Role::query()->where('slug', 'agency_owner')->firstOrFail());

        return [$user, $agency];
    }
}
