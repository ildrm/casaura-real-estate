<?php

namespace Tests\Feature\Api;

use App\Models\AgencyMember;
use App\Models\Role;
use App\Models\User;
use App\Notifications\AgencyInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesAgencyTenant;
use Tests\TestCase;

class MembershipLifecycleTest extends TestCase
{
    use CreatesAgencyTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Notification::fake();
    }

    /** P1 membership AC-1, AC-2. */
    public function test_new_user_invitation_sets_password_verifies_email_and_is_single_use(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        $this->actAsAgencyOwner($owner);
        $response = $this->postJson('/api/v1/agency/team', [
            'name' => 'Invited Agent',
            'email' => 'invited@example.com',
            'role_slug' => 'agent',
        ], $this->agencyHeaders($agency))->assertCreated();
        $user = User::query()->where('email', 'invited@example.com')->firstOrFail();
        $member = AgencyMember::query()->where('agency_id', $agency->id)->where('user_id', $user->id)->firstOrFail();
        $token = $this->invitationTokenFor($user);

        $this->assertSame(hash('sha256', $token), $member->invitation_token_hash);
        $this->assertNull($user->email_verified_at);
        $this->assertStringNotContainsString($token, $response->getContent());
        $this->assertStringNotContainsString('invitation_token_hash', $response->getContent());

        $this->postJson("/api/v1/auth/invitations/{$token}/accept", [
            'password' => 'InviteSecure123!',
            'password_confirmation' => 'InviteSecure123!',
        ])->assertOk()->assertJsonPath('data.email', 'invited@example.com');
        $this->assertSame('active', $member->refresh()->status);
        $this->assertNull($member->invitation_token_hash);
        $this->assertNotNull($user->refresh()->email_verified_at);
        $this->assertTrue(Hash::check('InviteSecure123!', $user->password));
        $this->assertDatabaseHas('audit_logs', ['agency_id' => $agency->id, 'action' => 'agency.invitation_accepted']);

        $this->postJson("/api/v1/auth/invitations/{$token}/accept", [
            'password' => 'InviteSecure123!',
            'password_confirmation' => 'InviteSecure123!',
        ])->assertUnprocessable()->assertJsonPath('error.code', 'INVITATION_INVALID');
    }

    /** P1 membership AC-3. */
    public function test_existing_account_invitation_requires_the_matching_authenticated_principal(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        $existing = User::factory()->create(['email' => 'existing@example.com']);
        $passwordHash = $existing->password;
        $this->actAsAgencyOwner($owner);
        $this->postJson('/api/v1/agency/team', [
            'name' => 'Existing User',
            'email' => $existing->email,
            'role_slug' => 'agent',
        ], $this->agencyHeaders($agency))->assertCreated();
        $token = $this->invitationTokenFor($existing);

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/v1/auth/invitations/{$token}/accept")
            ->assertUnauthorized()->assertJsonPath('error.code', 'INVITATION_AUTH_REQUIRED');

        Sanctum::actingAs($existing);
        $this->postJson("/api/v1/auth/invitations/{$token}/accept")
            ->assertOk()->assertJsonPath('data.id', $existing->id);
        $this->assertSame($passwordHash, $existing->refresh()->password);
        $this->assertDatabaseHas('agency_members', ['agency_id' => $agency->id, 'user_id' => $existing->id, 'status' => 'active']);
    }

    /** P1 membership AC-4, AC-5. */
    public function test_resend_invalidates_old_token_and_cancel_invalidates_current_token(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        $this->actAsAgencyOwner($owner);
        $memberId = $this->postJson('/api/v1/agency/team', [
            'name' => 'Lifecycle Agent',
            'email' => 'lifecycle@example.com',
            'role_slug' => 'agent',
        ], $this->agencyHeaders($agency))->assertCreated()->json('data.id');
        $user = User::query()->where('email', 'lifecycle@example.com')->firstOrFail();
        $oldToken = $this->invitationTokenFor($user);

        Notification::fake();
        $this->postJson("/api/v1/agency/team/{$memberId}/invitation", [], $this->agencyHeaders($agency))->assertOk();
        $newToken = $this->invitationTokenFor($user);
        $this->assertNotSame($oldToken, $newToken);
        $this->postJson("/api/v1/auth/invitations/{$oldToken}/accept", [
            'password' => 'InviteSecure123!', 'password_confirmation' => 'InviteSecure123!',
        ])->assertUnprocessable()->assertJsonPath('error.code', 'INVITATION_INVALID');

        $this->deleteJson("/api/v1/agency/team/{$memberId}/invitation", [], $this->agencyHeaders($agency))->assertNoContent();
        $this->postJson("/api/v1/auth/invitations/{$newToken}/accept", [
            'password' => 'InviteSecure123!', 'password_confirmation' => 'InviteSecure123!',
        ])->assertUnprocessable()->assertJsonPath('error.code', 'INVITATION_INVALID');
        $this->assertDatabaseHas('agency_members', ['id' => $memberId, 'status' => 'inactive']);
    }

    /** P1 membership AC-4; EC-2. */
    public function test_expired_invitation_fails_and_an_inactive_membership_can_be_reinvited(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        $this->actAsAgencyOwner($owner);
        $memberId = $this->postJson('/api/v1/agency/team', [
            'name' => 'Returning Agent', 'email' => 'returning@example.com', 'role_slug' => 'agent',
        ], $this->agencyHeaders($agency))->assertCreated()->json('data.id');
        $user = User::query()->where('email', 'returning@example.com')->firstOrFail();
        $expiredToken = $this->invitationTokenFor($user);
        AgencyMember::query()->whereKey($memberId)->update(['invitation_expires_at' => now()->subMinute()]);

        $this->postJson("/api/v1/auth/invitations/{$expiredToken}/accept", [
            'password' => 'InviteSecure123!', 'password_confirmation' => 'InviteSecure123!',
        ])->assertStatus(410)->assertJsonPath('error.code', 'INVITATION_EXPIRED');
        $this->deleteJson("/api/v1/agency/team/{$memberId}/invitation", [], $this->agencyHeaders($agency))->assertNoContent();

        Notification::fake();
        $response = $this->postJson('/api/v1/agency/team', [
            'name' => 'Returning Agent', 'email' => 'RETURNING@example.com', 'role_slug' => 'agent',
        ], $this->agencyHeaders($agency))->assertCreated()->assertJsonPath('data.id', $memberId);
        $this->assertSame($memberId, $response->json('data.id'));
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseHas('agency_members', ['id' => $memberId, 'status' => 'invited']);
        $this->invitationTokenFor($user);
    }

    /** P1 membership AC-6, AC-7. */
    public function test_update_cannot_activate_invites_or_assign_a_role_above_the_actor_ceiling(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        $this->actAsAgencyOwner($owner);
        $memberId = $this->postJson('/api/v1/agency/team', [
            'name' => 'Pending Agent', 'email' => 'pending@example.com', 'role_slug' => 'agent',
        ], $this->agencyHeaders($agency))->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/agency/team/{$memberId}", ['status' => 'active'], $this->agencyHeaders($agency))
            ->assertUnprocessable();
        $this->assertDatabaseHas('agency_members', ['id' => $memberId, 'status' => 'invited']);

        $manager = User::factory()->create();
        $managerMember = AgencyMember::query()->create([
            'agency_id' => $agency->id, 'user_id' => $manager->id, 'status' => 'active', 'accepted_at' => now(),
        ]);
        $managerMember->roles()->attach(Role::query()->where('slug', 'agency_manager')->firstOrFail());
        Sanctum::actingAs($manager);
        $this->postJson('/api/v1/agency/team', [
            'name' => 'Forbidden Owner', 'email' => 'forbidden-owner@example.com', 'role_slug' => 'agency_owner',
        ], $this->agencyHeaders($agency))->assertForbidden()->assertJsonPath('error.code', 'TEAM_ROLE_ASSIGNMENT_DENIED');
    }

    /** P1 membership AC-8. */
    public function test_last_owner_cannot_be_removed_and_canonical_owner_moves_to_another_owner(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        $ownerMember = AgencyMember::query()->where('agency_id', $agency->id)->where('user_id', $owner->id)->firstOrFail();
        $this->actAsAgencyOwner($owner);
        $this->patchJson("/api/v1/agency/team/{$ownerMember->id}", ['status' => 'inactive'], $this->agencyHeaders($agency))
            ->assertConflict()->assertJsonPath('error.code', 'LAST_OWNER_REQUIRED');

        $secondOwner = User::factory()->create();
        $secondMember = AgencyMember::query()->create([
            'agency_id' => $agency->id, 'user_id' => $secondOwner->id, 'status' => 'active', 'accepted_at' => now(),
        ]);
        $secondMember->roles()->attach(Role::query()->where('slug', 'agency_owner')->firstOrFail());
        $this->patchJson("/api/v1/agency/team/{$ownerMember->id}", ['role_slug' => 'agency_manager'], $this->agencyHeaders($agency))
            ->assertOk();
        $this->assertSame($secondOwner->id, $agency->refresh()->owner_user_id);
    }

    /** P1 membership AC-9. */
    public function test_storefront_team_visibility_is_explicit_opt_in(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        $ownerMember = AgencyMember::query()->where('agency_id', $agency->id)->where('user_id', $owner->id)->firstOrFail();
        $this->getJson("/api/v1/public/agencies/{$agency->slug}")->assertOk()->assertJsonCount(0, 'data.team');

        $this->actAsAgencyOwner($owner);
        $this->patchJson("/api/v1/agency/team/{$ownerMember->id}", [
            'is_public' => true,
            'public_position' => 1,
        ], $this->agencyHeaders($agency))->assertOk()->assertJsonPath('data.is_public', true);
        $this->getJson("/api/v1/public/agencies/{$agency->slug}")->assertOk()
            ->assertJsonCount(1, 'data.team')->assertJsonPath('data.team.0.name', $owner->name);
    }

    private function invitationTokenFor(User $user): string
    {
        $token = null;
        Notification::assertSentTo($user, AgencyInvitation::class, function (AgencyInvitation $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });

        return (string) $token;
    }
}
