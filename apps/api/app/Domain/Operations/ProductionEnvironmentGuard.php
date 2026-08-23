<?php

namespace App\Domain\Operations;

use Illuminate\Encryption\Encrypter;
use LogicException;

final class ProductionEnvironmentGuard
{
    public function assertReady(): void
    {
        if (! app()->environment('production') || ! config('production.guard_enabled')) {
            return;
        }

        $violations = [];
        $this->require(! config('app.debug'), 'APP_DEBUG must be false.', $violations);
        $this->require($this->validEncryptionKey(), 'APP_KEY must be a valid key for the configured cipher.', $violations);
        $this->require($this->isPublicHttpsUrl((string) config('app.url')), 'APP_URL must be a public HTTPS URL.', $violations);
        $this->require($this->isPublicHttpsUrl((string) config('identity.frontend_url')), 'FRONTEND_URL must be a public HTTPS URL.', $violations);
        $this->require(config('session.secure') === true, 'SESSION_SECURE_COOKIE must be true.', $violations);
        $this->require(config('session.encrypt') === true, 'SESSION_ENCRYPT must be true.', $violations);
        $this->require(config('session.http_only') === true, 'SESSION_HTTP_ONLY must be true.', $violations);
        $this->require(in_array(config('session.same_site'), ['lax', 'strict'], true), 'SESSION_SAME_SITE must be lax or strict.', $violations);
        $this->require(config('session.driver') === 'redis', 'SESSION_DRIVER must be redis.', $violations);
        $this->require(config('database.default') === 'pgsql', 'DB_CONNECTION must be pgsql.', $violations);
        $this->require(config('cache.default') === 'redis', 'CACHE_STORE must be redis.', $violations);
        $this->require(config('queue.default') === 'redis', 'QUEUE_CONNECTION must be redis.', $violations);
        $this->require($this->hasProtectedRedis(), 'Redis must use an authenticated URL or password.', $violations);
        $this->require($this->hasProtectedDatabase(), 'PostgreSQL credentials must be non-default and non-empty.', $violations);
        $this->require(config('filesystems.disks.listing_media.driver') === 's3', 'LISTING_MEDIA_DISK must be s3.', $violations);
        $this->require(config('filesystems.disks.privacy_exports.driver') === 's3', 'PRIVACY_EXPORT_DISK must be s3.', $violations);
        $this->require($this->hasObjectStorageConfiguration(), 'Object storage requires a bucket, region, and secure endpoint.', $violations);
        $this->require(config('media.scanner') === 'clamav', 'MEDIA_SCANNER must be clamav.', $violations);
        $this->require(! config('search.allow_destructive_reset'), 'SEARCH_ALLOW_DESTRUCTIVE_RESET must be false.', $violations);
        $this->require($this->hasDeliveryMailer(), 'MAIL_MAILER must use a production delivery transport.', $violations);
        $this->require(config('logging.default') === 'stderr', 'LOG_CHANNEL must be stderr for structured collection.', $violations);
        $this->require($this->hasSafeCorsConfiguration(), 'CORS must allow only the configured frontend origin.', $violations);
        $this->require(filled(config('identity.legal.version')), 'LEGAL_DOCUMENT_VERSION is required.', $violations);
        $this->require(filled(config('privacy.inquiry_consent_version')), 'INQUIRY_CONSENT_VERSION is required.', $violations);
        $this->require($this->hasResoProviderConfiguration(), 'RESO requires approved origins and a readable mounted secret directory.', $violations);
        $this->require($this->hasOpenAiProviderConfiguration(), 'AI_DRIVER must be openai with a public HTTPS endpoint and secret.', $violations);
        $this->require($this->hasStripeProviderConfiguration(), 'BILLING_DRIVER must be stripe with checkout, webhook, price, and hosted return configuration.', $violations);

        if ($violations !== []) {
            throw new LogicException("Unsafe production configuration:\n- ".implode("\n- ", $violations));
        }
    }

    /** @param list<string> $violations */
    private function require(bool $condition, string $message, array &$violations): void
    {
        if (! $condition) {
            $violations[] = $message;
        }
    }

    private function validEncryptionKey(): bool
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            $key = is_string($decoded) ? $decoded : '';
        }

        return Encrypter::supported($key, (string) config('app.cipher'));
    }

    private function isPublicHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = mb_strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.test')) {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_IP) === false;
    }

    private function hasProtectedDatabase(): bool
    {
        $connection = config('database.connections.pgsql');
        $password = (string) ($connection['password'] ?? '');

        return filled($connection['database'] ?? null)
            && filled($connection['username'] ?? null)
            && $password !== ''
            && ! in_array($password, ['password', 'postgres', 'casaura_local_only'], true);
    }

    private function hasProtectedRedis(): bool
    {
        $connection = config('database.redis.default');
        $url = (string) ($connection['url'] ?? '');
        $password = (string) ($connection['password'] ?? '');

        return $url !== '' || ($password !== '' && $password !== 'null');
    }

    private function hasObjectStorageConfiguration(): bool
    {
        $disk = config('filesystems.disks.listing_media');
        $endpoint = (string) ($disk['endpoint'] ?? '');

        return filled($disk['bucket'] ?? null)
            && filled($disk['region'] ?? null)
            && ($endpoint === '' || str_starts_with($endpoint, 'https://'));
    }

    private function hasDeliveryMailer(): bool
    {
        $mailer = (string) config('mail.default');
        $address = (string) config('mail.from.address');

        return ! in_array($mailer, ['', 'array', 'log'], true)
            && filter_var($address, FILTER_VALIDATE_EMAIL) !== false
            && ! str_ends_with(mb_strtolower($address), '@example.com')
            && ! str_ends_with(mb_strtolower($address), '.test');
    }

    private function hasSafeCorsConfiguration(): bool
    {
        $frontend = rtrim((string) config('identity.frontend_url'), '/');
        $origins = config('cors.allowed_origins');

        return is_array($origins)
            && $origins === [$frontend]
            && config('cors.allowed_origins_patterns') === []
            && config('cors.supports_credentials') === true;
    }

    private function hasResoProviderConfiguration(): bool
    {
        $origins = config('integrations.approved_origins');
        $directory = (string) config('integrations.secret_directory');

        return is_array($origins)
            && $origins !== []
            && collect($origins)->every(fn ($origin) => is_string($origin)
                && preg_match('/^(?!localhost$)(?!.*\.(?:local|test)$)[a-z0-9.-]+$/i', $origin) === 1)
            && str_starts_with($directory, DIRECTORY_SEPARATOR)
            && is_dir($directory)
            && is_readable($directory);
    }

    private function hasOpenAiProviderConfiguration(): bool
    {
        return config('ai.driver') === 'openai'
            && filled(config('ai.api_key'))
            && $this->isPublicHttpsUrl((string) config('ai.base_url'))
            && (int) config('ai.timeout_seconds') > 0
            && (int) config('ai.timeout_seconds') <= 15;
    }

    private function hasStripeProviderConfiguration(): bool
    {
        $frontend = rtrim((string) config('identity.frontend_url'), '/');
        $returns = [
            (string) config('billing.checkout_success_url'),
            (string) config('billing.checkout_cancel_url'),
            (string) config('billing.portal_return_url'),
        ];

        return config('billing.driver') === 'stripe'
            && str_starts_with((string) config('billing.stripe.secret_key'), 'sk_')
            && str_starts_with((string) config('billing.stripe.webhook_secret'), 'whsec_')
            && str_starts_with((string) config('billing.stripe.professional_price_id'), 'price_')
            && rtrim((string) config('billing.stripe.api_url'), '/') === 'https://api.stripe.com'
            && collect($returns)->every(fn (string $url) => $this->isPublicHttpsUrl($url)
                && str_starts_with($url, $frontend.'/'));
    }
}
