<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OperationsHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('listing_media');
        config([
            'operations.require_worker_heartbeat' => false,
            'operations.require_scheduler_heartbeat' => false,
            'operations.release_id' => 'test-release',
        ]);
    }

    public function test_liveness_does_not_require_dependencies(): void
    {
        $this->getJson('/api/v1/health/live')
            ->assertOk()
            ->assertHeader('Release-ID', 'test-release')
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('release', 'test-release');
    }

    public function test_readiness_checks_required_dependencies_without_disclosing_details(): void
    {
        $this->getJson('/api/v1/health/ready')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonMissingPath('components');
    }
}
