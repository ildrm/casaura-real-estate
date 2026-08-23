<?php

namespace Tests\Feature\Api;

use App\Models\DataSubjectRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesAgencyTenant;
use Tests\TestCase;

class PrivacyLifecycleTest extends TestCase
{
    use CreatesAgencyTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('privacy_exports');
    }

    public function test_authenticated_user_can_request_and_download_an_encrypted_export(): void
    {
        [$user] = $this->createAgencyOwner();
        $this->actAsAgencyOwner($user);

        $response = $this->postJson('/api/v1/account/privacy/requests', ['type' => 'export'])
            ->assertAccepted()
            ->assertJsonPath('data.type', 'export')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.download_available', true);
        $requestId = $response->json('data.id');
        $record = DataSubjectRequest::query()->findOrFail($requestId);

        Storage::disk('privacy_exports')->assertExists($record->output_storage_key);
        $download = $this->get("/api/v1/account/privacy/requests/{$requestId}/download")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        $payload = json_decode($download->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('casaura-data-export-v1', $payload['format']);
        $this->assertSame($user->id, $payload['account']['id']);
        $this->assertArrayNotHasKey('password', $payload['account']);
    }

    public function test_operator_deletion_anonymizes_non_owner_and_revokes_access(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $request = DataSubjectRequest::query()->create([
            'subject_user_id' => $user->id,
            'requested_by_user_id' => $user->id,
            'type' => 'deletion',
            'status' => 'pending_operator_review',
            'requested_at' => now(),
        ]);
        DB::table('sessions')->insert([
            'id' => Str::random(40),
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'test',
            'last_activity' => now()->getTimestamp(),
        ]);

        $this->artisan("privacy:process-deletion {$request->id} --approval-reference=privacy-ticket-123")
            ->assertSuccessful();

        $user->refresh();
        $this->assertSame('Deleted user', $user->name);
        $this->assertSame('suspended', $user->status);
        $this->assertStringStartsWith('deleted+', $user->email);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('data_subject_requests', ['id' => $request->id, 'status' => 'completed']);
    }

    public function test_retention_pseudonymizes_then_deletes_raw_analytics_and_expires_exports(): void
    {
        [$user, $agency] = $this->createAgencyOwner();
        $recentId = (string) Str::uuid();
        $oldId = (string) Str::uuid();
        DB::table('analytics_events')->insert([
            [
                'id' => $recentId,
                'agency_id' => $agency->id,
                'type' => 'listing.viewed',
                'anonymous_session_hash' => hash('sha256', 'recent'),
                'occurred_at' => now()->subDays(8),
            ],
            [
                'id' => $oldId,
                'agency_id' => $agency->id,
                'type' => 'listing.viewed',
                'anonymous_session_hash' => hash('sha256', 'old'),
                'occurred_at' => now()->subDays(91),
            ],
        ]);
        $key = "privacy-exports/{$user->id}/expired.enc";
        Storage::disk('privacy_exports')->put($key, 'encrypted');
        $export = DataSubjectRequest::query()->create([
            'subject_user_id' => $user->id,
            'requested_by_user_id' => $user->id,
            'type' => 'export',
            'status' => 'completed',
            'output_storage_key' => $key,
            'output_checksum_sha256' => hash('sha256', 'encrypted'),
            'requested_at' => now()->subDays(8),
            'completed_at' => now()->subDays(8),
            'expires_at' => now()->subDay(),
        ]);

        $this->artisan('privacy:enforce-retention')->assertSuccessful();

        $this->assertDatabaseHas('analytics_events', ['id' => $recentId, 'anonymous_session_hash' => null]);
        $this->assertDatabaseMissing('analytics_events', ['id' => $oldId]);
        $this->assertDatabaseHas('data_subject_requests', ['id' => $export->id, 'status' => 'expired']);
        Storage::disk('privacy_exports')->assertMissing($key);
    }
}
