<?php

namespace App\Domain\Integrations;

use App\Domain\ApiException;
use App\Models\ProviderConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

final class ResoWebApiClient implements RealEstateDataProviderClient
{
    public function metadata(ProviderConnection $connection): array
    {
        $this->assertSafeUrl($connection->base_url);
        $this->assertSafeUrl($connection->token_url);
        $url = rtrim($connection->base_url, '/').'/$metadata';
        $this->assertSafeUrl($url);
        $response = $this->request($connection)->accept('application/xml')->get($url);
        if (! $response->successful()) {
            throw new ApiException('PROVIDER_METADATA_FAILED', 'The provider metadata could not be loaded.', 502);
        }
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $loaded = $document->loadXML($response->body(), LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            throw new ApiException('PROVIDER_METADATA_INVALID', 'The provider returned invalid metadata.', 502);
        }
        $xpath = new \DOMXPath($document);
        $resources = [];
        foreach ($xpath->query('//*[local-name()="EntityType"]') ?: [] as $entity) {
            if (! $entity instanceof \DOMElement || count($resources) >= 100) {
                continue;
            }
            $fields = [];
            foreach ($xpath->query('./*[local-name()="Property"]', $entity) ?: [] as $property) {
                if (! $property instanceof \DOMElement || count($fields) >= 1000) {
                    continue;
                }
                $name = $property->getAttribute('Name');
                if ($name !== '') {
                    $fields[] = ['name' => $name, 'type' => $property->getAttribute('Type') ?: 'unknown'];
                }
            }
            $name = $entity->getAttribute('Name');
            if ($name !== '') {
                $resources[] = ['name' => $name, 'fields' => $fields];
            }
        }
        if ($resources === []) {
            throw new ApiException('PROVIDER_METADATA_INVALID', 'The provider metadata contains no readable resources.', 502);
        }

        return ['resources' => $resources];
    }

    public function records(ProviderConnection $connection, string $resource, ?string $cursor = null): iterable
    {
        $this->assertSafeUrl($connection->base_url);
        $this->assertSafeUrl($connection->token_url);
        $request = $this->request($connection)->acceptJson()->withHeaders(['OData-Version' => '4.01']);
        if ($cursor && str_starts_with($cursor, 'https://')) {
            $url = $cursor;
        } else {
            $parameters = [
                '$top' => min(500, max(1, (int) config('integrations.page_size', 200))),
                '$orderby' => 'ModificationTimestamp asc',
            ];
            if ($cursor) {
                try {
                    $parameters['$filter'] = 'ModificationTimestamp gt '.Carbon::parse($cursor)->utc()->toIso8601String();
                } catch (\Throwable) {
                    throw new ApiException('PROVIDER_CURSOR_INVALID', 'The provider cursor is invalid.', 422);
                }
            }
            $url = rtrim($connection->base_url, '/').'/'.rawurlencode($resource).'?'.http_build_query($parameters);
        }

        do {
            $this->assertSafeUrl($url);
            $response = $request->get($url);
            if (! $response->successful()) {
                throw new ApiException('PROVIDER_REQUEST_FAILED', 'The provider request failed.', 502, [
                    'provider_status' => $response->status(),
                ]);
            }
            $odataVersion = (string) $response->header('OData-Version');
            if ($odataVersion !== '' && ! in_array($odataVersion, ['4.0', '4.01'], true)) {
                throw new ApiException('PROVIDER_VERSION_UNSUPPORTED', 'The provider returned an unsupported OData version.', 502);
            }
            $payload = $response->json();
            $records = is_array($payload) ? ($payload['value'] ?? null) : null;
            if (! is_array($records)) {
                throw new ApiException('PROVIDER_PAYLOAD_INVALID', 'The provider returned an invalid OData payload.', 502);
            }
            if (count($records) > 500) {
                throw new ApiException('PROVIDER_PAGE_TOO_LARGE', 'The provider returned more than 500 records in one page.', 502);
            }
            foreach ($records as $record) {
                if (is_array($record)) {
                    yield $record;
                }
            }
            $next = is_array($payload) ? ($payload['@odata.nextLink'] ?? null) : null;
            $url = is_string($next) && $next !== '' ? $next : null;
        } while ($url !== null);
    }

    private function request(ProviderConnection $connection): PendingRequest
    {
        $secret = $this->secret($connection->secret_reference);
        if ($secret === null) {
            throw new ApiException('PROVIDER_SECRET_UNAVAILABLE', 'The provider credential is not configured.', 503);
        }
        $tokenResponse = Http::asForm()
            ->withOptions(['allow_redirects' => false])
            ->timeout((int) config('integrations.timeout_seconds', 15))
            ->post($connection->token_url, [
                'grant_type' => 'client_credentials',
                'client_id' => $connection->client_id,
                'client_secret' => $secret,
            ]);
        if (! $tokenResponse->successful() || ! is_string($tokenResponse->json('access_token'))) {
            throw new ApiException('PROVIDER_AUTHENTICATION_FAILED', 'The provider could not be authenticated.', 502);
        }

        return Http::withToken($tokenResponse->json('access_token'))
            ->withOptions(['allow_redirects' => false])
            ->timeout((int) config('integrations.timeout_seconds', 15));
    }

    private function assertSafeUrl(string $url): void
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host'])) {
            throw new ApiException('PROVIDER_ORIGIN_UNAPPROVED', 'Provider endpoints must use approved HTTPS origins.', 422);
        }
        $approved = config('integrations.approved_origins', []);
        if (app()->environment('production') && (! is_array($approved) || ! in_array($parts['host'], $approved, true))) {
            throw new ApiException('PROVIDER_ORIGIN_UNAPPROVED', 'The provider origin is not approved.', 422);
        }
    }

    private function secret(string $reference): ?string
    {
        $configured = config('integrations.secrets.'.$reference);
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }
        if (preg_match('/^[A-Za-z0-9_.-]+$/', $reference) !== 1) {
            return null;
        }
        $directory = rtrim((string) config('integrations.secret_directory'), DIRECTORY_SEPARATOR);
        if ($directory === '' || ! str_starts_with($directory, DIRECTORY_SEPARATOR)) {
            return null;
        }
        $path = $directory.DIRECTORY_SEPARATOR.$reference;
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }
        $secret = trim((string) file_get_contents($path));

        return $secret !== '' ? $secret : null;
    }
}
