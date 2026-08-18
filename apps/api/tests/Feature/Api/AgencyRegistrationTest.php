<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgencyRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_agency_registration_creates_the_tenant_owner_role_and_configured_promotion(): void
    {
        $this->seed();

        $response = $this->withHeader('Origin', 'http://localhost:3000')
            ->postJson('/api/v1/auth/register-agency', [
                'name' => 'Maya Patel',
                'email' => 'maya@greenway.test',
                'password' => 'SecurePass123!',
                'password_confirmation' => 'SecurePass123!',
                'agency_name' => 'Greenway Realty',
                'timezone' => 'America/Los_Angeles',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Maya Patel')
            ->assertJsonPath('data.memberships.0.agency.name', 'Greenway Realty')
            ->assertJsonPath('data.memberships.0.roles.0', 'agency_owner')
            ->assertJsonFragment(['agency.manage_members']);

        $this->assertDatabaseHas('agencies', [
            'name' => 'Greenway Realty',
            'slug' => 'greenway-realty',
            'verification_status' => 'unverified',
        ]);
        $this->assertDatabaseHas('agency_branches', ['name' => 'Main office', 'is_primary' => true]);
        $this->assertDatabaseHas('subscriptions', ['status' => 'active', 'billing_status' => 'not_required']);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_registration_requires_a_strong_confirmed_password(): void
    {
        $this->seed();

        $this->postJson('/api/v1/auth/register-agency', [
            'name' => 'Maya Patel',
            'email' => 'maya@greenway.test',
            'password' => 'weak',
            'password_confirmation' => 'weak',
            'agency_name' => 'Greenway Realty',
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['fields' => ['password']]]);
    }
}
