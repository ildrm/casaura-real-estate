<?php

namespace Tests\Feature;

use App\Domain\Operations\ProductionEnvironmentGuard;
use LogicException;
use Tests\TestCase;

class ProductionEnvironmentGuardTest extends TestCase
{
    public function test_non_production_environments_are_not_rejected(): void
    {
        app(ProductionEnvironmentGuard::class)->assertReady();

        $this->assertTrue(true);
    }

    public function test_unsafe_production_configuration_is_rejected_with_all_relevant_failures(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config([
            'app.env' => 'production',
            'app.debug' => true,
            'app.key' => '',
            'app.url' => 'http://localhost',
            'identity.frontend_url' => 'http://localhost:3000',
            'session.secure' => false,
            'session.encrypt' => false,
            'session.driver' => 'database',
            'database.default' => 'sqlite',
            'cache.default' => 'database',
            'queue.default' => 'sync',
            'filesystems.disks.listing_media.driver' => 'local',
            'media.scanner' => 'signature',
            'mail.default' => 'log',
        ]);

        try {
            app(ProductionEnvironmentGuard::class)->assertReady();
            $this->fail('The production guard did not reject unsafe configuration.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('APP_DEBUG must be false', $exception->getMessage());
            $this->assertStringContainsString('APP_KEY must be a valid key', $exception->getMessage());
            $this->assertStringContainsString('LISTING_MEDIA_DISK must be s3', $exception->getMessage());
            $this->assertStringContainsString('MEDIA_SCANNER must be clamav', $exception->getMessage());
        }
    }

    public function test_complete_production_configuration_passes(): void
    {
        $secretDirectory = sys_get_temp_dir().'/casaura-provider-secrets-'.str()->uuid();
        mkdir($secretDirectory, 0700, true);
        $this->app->detectEnvironment(fn () => 'production');
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.key' => 'base64:'.base64_encode(str_repeat('x', 32)),
            'app.url' => 'https://api.casaura.example',
            'identity.frontend_url' => 'https://casaura.example',
            'identity.legal.version' => '2026-08-22',
            'session.secure' => true,
            'session.encrypt' => true,
            'session.http_only' => true,
            'session.same_site' => 'lax',
            'session.driver' => 'redis',
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => 'casaura',
            'database.connections.pgsql.username' => 'casaura_app',
            'database.connections.pgsql.password' => 'unique-managed-secret',
            'database.redis.default.url' => 'rediss://managed-redis.internal:6379',
            'cache.default' => 'redis',
            'queue.default' => 'redis',
            'filesystems.disks.listing_media.driver' => 's3',
            'filesystems.disks.privacy_exports.driver' => 's3',
            'filesystems.disks.listing_media.bucket' => 'casaura-production-media',
            'filesystems.disks.listing_media.region' => 'us-east-1',
            'filesystems.disks.listing_media.endpoint' => '',
            'media.scanner' => 'clamav',
            'privacy.inquiry_consent_version' => '2026-08-22',
            'search.allow_destructive_reset' => false,
            'mail.default' => 'smtp',
            'mail.from.address' => 'delivery@casaura.example',
            'logging.default' => 'stderr',
            'cors.allowed_origins' => ['https://casaura.example'],
            'cors.allowed_origins_patterns' => [],
            'cors.supports_credentials' => true,
            'integrations.approved_origins' => ['api.example-mls.com', 'auth.example-mls.com'],
            'integrations.secret_directory' => $secretDirectory,
            'ai.driver' => 'openai',
            'ai.api_key' => 'sk-managed-openai-secret',
            'ai.base_url' => 'https://api.openai.com',
            'ai.timeout_seconds' => 15,
            'billing.driver' => 'stripe',
            'billing.stripe.secret_key' => 'sk_live_managed',
            'billing.stripe.webhook_secret' => 'whsec_managed',
            'billing.stripe.professional_price_id' => 'price_professional',
            'billing.stripe.api_url' => 'https://api.stripe.com',
            'billing.checkout_success_url' => 'https://casaura.example/agency/billing?checkout=success',
            'billing.checkout_cancel_url' => 'https://casaura.example/agency/billing?checkout=cancelled',
            'billing.portal_return_url' => 'https://casaura.example/agency/billing',
        ]);

        try {
            app(ProductionEnvironmentGuard::class)->assertReady();
        } finally {
            rmdir($secretDirectory);
        }

        $this->assertTrue(true);
    }
}
