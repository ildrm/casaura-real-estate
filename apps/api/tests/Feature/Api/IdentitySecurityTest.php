<?php

namespace Tests\Feature\Api;

use App\Domain\Identity\Totp;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesAgencyTenant;
use Tests\TestCase;

class IdentitySecurityTest extends TestCase
{
    use CreatesAgencyTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['identity.legal.version' => '2026-08-22']);
        config(['identity.legal.text' => 'Casaura Terms and Privacy Notice, version 2026-08-22.']);
    }

    /** P1 identity AC-1, AC-2. */
    public function test_agency_registration_requires_current_consent_and_sends_verification(): void
    {
        Notification::fake();
        $this->withHeader('Origin', 'http://localhost:3000');
        $payload = $this->agencyRegistrationPayload();

        $this->postJson('/api/v1/auth/register-agency', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->assertDatabaseCount('users', 0);

        $response = $this->postJson('/api/v1/auth/register-agency', [
            ...$payload,
            'consent' => true,
            'consent_version' => '2026-08-22',
        ])->assertCreated();
        $user = User::query()->where('email', $payload['email'])->firstOrFail();

        $this->assertNull($user->email_verified_at);
        $this->assertDatabaseHas('consent_records', [
            'user_id' => $user->id,
            'purpose' => 'agency_registration',
            'document_version' => '2026-08-22',
            'source' => 'web_registration',
        ]);
        $this->assertSame(hash('sha256', (string) config('identity.legal.text')), DB::table('consent_records')->value('legal_text_sha256'));
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->assertStringNotContainsString('verification_token', strtolower($response->getContent()));
        $this->getJson('/api/v1/me')
            ->assertSuccessful()
            ->assertJsonPath('data.id', $user->id);
    }

    /** P1 identity AC-3. */
    public function test_unverified_owner_can_inspect_principal_but_cannot_mutate_tenant_state(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        $owner->forceFill(['email_verified_at' => null])->save();
        Sanctum::actingAs($owner, ['mfa']);

        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.email_verified_at', null);
        $this->postJson('/api/v1/listings', $this->validListingPayload(), $this->agencyHeaders($agency))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'EMAIL_VERIFICATION_REQUIRED');
        $this->assertDatabaseCount('listings', 0);
    }

    /** P1 identity AC-5. */
    public function test_password_recovery_request_is_non_enumerating(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'known@example.com']);

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'KNOWN@example.com'])
            ->assertAccepted();
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'unknown@example.com'])
            ->assertAccepted();

        $this->assertSame($known->json(), $unknown->json());
        $known->assertJsonPath('data.message', 'If an eligible account exists, recovery instructions will be sent.');
        Notification::assertSentTo($user, ResetPassword::class);
        Notification::assertCount(1);
    }

    /** P1 identity AC-4. */
    public function test_signed_email_verification_is_bound_to_the_authenticated_user_and_audited(): void
    {
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->getJson($url)->assertOk()->assertJsonPath('data.verified', true);
        $this->assertNotNull($user->refresh()->email_verified_at);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $user->id, 'action' => 'user.email_verified']);

        $other = User::factory()->unverified()->create();
        Sanctum::actingAs($other);
        $this->getJson($url)->assertForbidden();
        $this->assertNull($other->refresh()->email_verified_at);
    }

    /** P1 identity AC-6. */
    public function test_password_reset_is_single_use_and_revokes_sessions_and_tokens(): void
    {
        $user = User::factory()->create(['email' => 'reset@example.com']);
        $token = Password::broker()->createToken($user);
        $user->createToken('stale-token');
        DB::table('sessions')->insert([
            'id' => 'stale-session',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'stale',
            'last_activity' => now()->timestamp,
        ]);

        $payload = [
            'email' => 'RESET@example.com',
            'token' => $token,
            'password' => 'NewSecurePass123!',
            'password_confirmation' => 'NewSecurePass123!',
        ];
        $this->postJson('/api/v1/auth/reset-password', $payload)->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('NewSecurePass123!', $user->password));
        $this->assertSame(2, $user->security_version);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('sessions', 0);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $user->id, 'action' => 'user.password_reset']);
        $this->postJson('/api/v1/auth/reset-password', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'PASSWORD_RESET_INVALID');
    }

    /** P1 identity AC-7. */
    public function test_privileged_owner_requires_mfa_but_consumer_does_not(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        $owner->forceFill(['mfa_secret' => null, 'mfa_confirmed_at' => null])->save();
        Sanctum::actingAs($owner);
        $this->patchJson('/api/v1/agency', ['name' => 'Protected Rename'], $this->agencyHeaders($agency))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'MFA_SETUP_REQUIRED');

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/account/engagements')->assertOk();
    }

    /** P1 identity AC-8. */
    public function test_mfa_setup_requires_password_and_returns_recovery_codes_once(): void
    {
        [$owner] = $this->createAgencyOwner();
        $owner->forceFill(['mfa_secret' => null, 'mfa_confirmed_at' => null])->save();
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/auth/mfa/setup', ['password' => 'wrong-password'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'IDENTITY_PASSWORD_INVALID');
        $setup = $this->postJson('/api/v1/auth/mfa/setup', ['password' => 'password'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['secret', 'provisioning_uri']]);
        $secret = $setup->json('data.secret');
        $rawStoredSecret = DB::table('users')->where('id', $owner->id)->value('mfa_secret');
        $this->assertNotSame($secret, $rawStoredSecret);

        $confirmation = $this->postJson('/api/v1/auth/mfa/confirm', [
            'code' => app(Totp::class)->currentCode($secret),
        ])->assertOk()->assertJsonCount(8, 'data.recovery_codes');
        $recoveryCodes = $confirmation->json('data.recovery_codes');
        $owner->refresh();
        $this->assertNotNull($owner->mfa_confirmed_at);
        $this->assertNotContains($recoveryCodes[0], $owner->mfa_recovery_codes);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $owner->id, 'action' => 'user.mfa_enabled']);

        $this->postJson('/api/v1/auth/mfa/confirm', ['code' => app(Totp::class)->currentCode($secret)])
            ->assertConflict()
            ->assertJsonPath('error.code', 'MFA_ALREADY_ENABLED');
    }

    /** P1 identity AC-9, AC-10. */
    public function test_privileged_login_requires_a_non_replayable_second_factor(): void
    {
        $this->withHeader('Origin', 'http://localhost:3000');
        [$owner] = $this->createAgencyOwner();
        $secret = app(Totp::class)->generateSecret();
        $owner->forceFill([
            'mfa_secret' => $secret,
            'mfa_confirmed_at' => now(),
            'mfa_recovery_codes' => [hash('sha256', 'RECOVERYCODEONE')],
        ])->save();

        $login = $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'password'])
            ->assertStatus(202)
            ->assertJsonPath('error.code', 'MFA_REQUIRED');
        $this->assertFalse(Auth::check());

        $code = app(Totp::class)->currentCode($secret);
        $this->postJson('/api/v1/auth/mfa/challenge', ['code' => $code])->assertOk();
        $this->assertTrue(Auth::check());
        $this->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'password'])->assertStatus(202);
        $this->postJson('/api/v1/auth/mfa/challenge', ['code' => $code])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'MFA_CODE_INVALID');
        $this->postJson('/api/v1/auth/mfa/challenge', ['code' => 'recovery-code-one'])->assertOk();
        $owner->refresh();
        $this->assertSame([], $owner->mfa_recovery_codes);
    }

    /** P1 identity AC-11. */
    public function test_security_version_change_revokes_an_established_token(): void
    {
        $user = User::factory()->create(['email' => 'session@example.com']);
        $token = $user->createToken('security-version-test')->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token];
        $this->getJson('/api/v1/me', $headers)->assertOk();

        $user->increment('security_version');
        $this->getJson('/api/v1/me', $headers)
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'SESSION_REVOKED');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /** P1 identity AC-12. */
    public function test_customer_registration_is_seeded_off(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Consumer',
            'email' => 'consumer@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'consent' => true,
            'consent_version' => '2026-08-22',
        ])->assertForbidden()->assertJsonPath('error.code', 'FEATURE_DISABLED');
        $this->assertDatabaseCount('users', 0);
    }

    /** @return array<string, mixed> */
    private function agencyRegistrationPayload(): array
    {
        return [
            'name' => 'Maya Patel',
            'email' => 'maya@greenway.test',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'agency_name' => 'Greenway Realty',
            'timezone' => 'America/Los_Angeles',
        ];
    }
}
