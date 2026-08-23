<?php

namespace App\Domain\Integrations;

use App\Domain\ApiException;
use App\Domain\Listings\ListingManager;
use App\Domain\Tenancy\AuditRecorder;
use App\Models\Address;
use App\Models\FieldMapping;
use App\Models\Listing;
use App\Models\Property;
use App\Models\ProviderConnection;
use App\Models\SyncJob;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class IntegrationSyncService
{
    public function __construct(
        private readonly RealEstateDataProviderClient $provider,
        private readonly ListingManager $listings,
        private readonly AuditRecorder $audit,
    ) {}

    public function run(Request $request, ProviderConnection $connection, SyncJob $sync): SyncJob
    {
        $mapping = FieldMapping::query()
            ->where('provider_connection_id', $connection->id)
            ->where('resource', 'Property')
            ->whereNotNull('activated_at')
            ->latest('version')
            ->first();
        if (! $mapping) {
            throw new ApiException('INTEGRATION_MAPPING_REQUIRED', 'An active Property mapping is required.', 422);
        }

        $sync->update(['status' => 'running', 'started_at' => now()]);
        $counts = ['records_fetched' => 0, 'records_imported' => 0, 'records_skipped' => 0, 'records_failed' => 0];
        $endCursor = $sync->start_cursor;

        try {
            foreach ($this->provider->records($connection, 'Property', $sync->start_cursor) as $record) {
                $counts['records_fetched']++;
                $payloadHash = hash('sha256', json_encode($record, JSON_THROW_ON_ERROR));
                $externalId = (string) data_get($record, $mapping->fields['external_id'] ?? 'ListingKey', '');
                $modifiedAt = data_get($record, $mapping->fields['modified_at'] ?? 'ModificationTimestamp');
                if (is_string($modifiedAt)) {
                    try {
                        $candidateCursor = Carbon::parse($modifiedAt)->utc()->toIso8601String();
                        if ($endCursor === null || Carbon::parse($candidateCursor)->isAfter(Carbon::parse($endCursor))) {
                            $endCursor = $candidateCursor;
                        }
                    } catch (Throwable) {
                        // Invalid provider timestamps are quarantined by canonical validation below.
                    }
                }
                if ($externalId === '') {
                    $sourceId = $this->quarantineSource(
                        $connection,
                        $sync,
                        $mapping,
                        'missing:'.$payloadHash,
                        $payloadHash,
                        $record,
                        'invalid',
                    );
                    $this->recordFailure($sync, $sourceId, 'external_id', 'PROVIDER_EXTERNAL_ID_MISSING', false);
                    $counts['records_failed']++;

                    continue;
                }
                $existing = DB::table('data_source_records')
                    ->where('provider_connection_id', $connection->id)
                    ->where('resource', 'Property')
                    ->where('external_record_id', $externalId)
                    ->where('payload_hash', $payloadHash)
                    ->first();
                if ($existing) {
                    $counts['records_skipped']++;

                    continue;
                }

                try {
                    $canonical = $this->map($record, $mapping->fields);
                    $duplicate = false;
                    DB::transaction(function () use ($request, $connection, $sync, $mapping, $record, $payloadHash, $externalId, $canonical, &$duplicate): void {
                        $prior = DB::table('data_source_records')
                            ->where('provider_connection_id', $connection->id)
                            ->where('resource', 'Property')
                            ->where('external_record_id', $externalId)
                            ->latest('created_at')
                            ->first();

                        if ($prior?->listing_id) {
                            $listing = Listing::query()
                                ->where('agency_id', $connection->agency_id)
                                ->findOrFail($prior->listing_id);
                            $listing = $this->listings->syncFromProvider($request, $listing, $canonical);
                        } else {
                            $candidate = $this->addressCandidate($connection->agency_id, $canonical);
                            if ($candidate) {
                                $sourceId = (string) Str::uuid();
                                $this->insertSource(
                                    $sourceId,
                                    $connection,
                                    $sync,
                                    $mapping,
                                    $externalId,
                                    $payloadHash,
                                    $record,
                                    'duplicate_review',
                                );
                                DB::table('duplicate_candidates')->insert([
                                    'id' => (string) Str::uuid(),
                                    'agency_id' => $connection->agency_id,
                                    'left_property_id' => $candidate->id,
                                    'right_property_id' => null,
                                    'data_source_record_id' => $sourceId,
                                    'score' => 0.8000,
                                    'reasons' => json_encode(['normalized_address'], JSON_THROW_ON_ERROR),
                                    'status' => 'pending',
                                    'version' => 1,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                                $duplicate = true;

                                return;
                            }
                            $listing = $this->listings->create($request, $canonical);
                        }

                        $this->insertSource(
                            (string) Str::uuid(),
                            $connection,
                            $sync,
                            $mapping,
                            $externalId,
                            $payloadHash,
                            $record,
                            'imported',
                            $listing->property_id,
                            $listing->id,
                        );
                    });
                    $counts[$duplicate ? 'records_skipped' : 'records_imported']++;
                } catch (ApiException $exception) {
                    $sourceId = $this->quarantineSource(
                        $connection,
                        $sync,
                        $mapping,
                        $externalId,
                        $payloadHash,
                        $record,
                        'invalid',
                    );
                    $this->recordFailure(
                        $sync,
                        $sourceId,
                        is_string($exception->context['field'] ?? null) ? $exception->context['field'] : null,
                        $exception->errorCode,
                        (bool) ($exception->context['retryable'] ?? ($exception->status >= 500)),
                    );
                    $counts['records_failed']++;
                } catch (Throwable) {
                    $sourceId = $this->quarantineSource(
                        $connection,
                        $sync,
                        $mapping,
                        $externalId,
                        $payloadHash,
                        $record,
                        'invalid',
                    );
                    $this->recordFailure($sync, $sourceId, null, 'PROVIDER_RECORD_INVALID', false);
                    $counts['records_failed']++;
                }
            }

            $status = $counts['records_failed'] > 0 ? 'partial' : 'completed';
            $sync->update([...$counts, 'status' => $status, 'end_cursor' => $endCursor, 'completed_at' => now()]);
            $connection->update(['last_sync_status' => $status, 'last_synced_at' => now()]);
            $this->audit->record($request, 'integration.sync_completed', $sync, null, [
                'status' => $status,
                ...$counts,
            ], $connection->agency_id);
        } catch (Throwable $exception) {
            $sync->update([...$counts, 'status' => 'failed', 'failure_code' => $exception instanceof ApiException
                ? $exception->errorCode
                : 'PROVIDER_SYNC_FAILED', 'completed_at' => now()]);
            $connection->update(['last_sync_status' => 'failed']);
            throw $exception;
        }

        return $sync->refresh();
    }

    /** @param array<string, string> $fields @return array<string, mixed> */
    private function map(array $record, array $fields): array
    {
        $value = fn (string $name, mixed $default = null) => data_get($record, $fields[$name] ?? $name, $default);
        $reference = trim((string) $value('reference'));
        $price = $value('price');
        if ($reference === '' || ! is_numeric($price)) {
            throw new ApiException('PROVIDER_RECORD_INVALID', 'The provider record is missing canonical fields.', 422);
        }
        $status = mb_strtolower((string) $value('status', 'active'));
        $intent = str_contains(mb_strtolower((string) $value('transaction_type', 'sale')), 'lease') ? 'rent' : 'sale';
        $areaUnit = mb_strtolower((string) $value('area_unit', 'SquareFeet'));
        $currency = strtoupper(trim((string) $value('currency', 'USD')));
        if ($currency !== 'USD') {
            throw new ApiException('PROVIDER_CURRENCY_UNSUPPORTED', 'Only USD provider records are supported at launch.', 422, [
                'field' => 'currency',
                'retryable' => false,
            ]);
        }
        $providerPropertyType = mb_strtolower((string) $value('property_type', 'house'));
        $providerPropertySubtype = mb_strtolower((string) $value('property_subtype', ''));
        $propertyTypeSlug = match (true) {
            str_contains($providerPropertyType.$providerPropertySubtype, 'apartment'),
            str_contains($providerPropertyType.$providerPropertySubtype, 'condominium') => 'apartment',
            str_contains($providerPropertyType.$providerPropertySubtype, 'townhouse') => 'townhouse',
            str_contains($providerPropertyType.$providerPropertySubtype, 'land') => 'land',
            str_contains($providerPropertyType.$providerPropertySubtype, 'commercial') => 'commercial',
            default => 'house',
        };

        return [
            'reference' => mb_substr($reference, 0, 120),
            'intent' => $intent,
            'property_type_slug' => $propertyTypeSlug,
            'title' => mb_substr((string) $value('title', "Imported property {$reference}"), 0, 160),
            'description' => mb_substr((string) $value('description', 'Imported licensed property record.'), 0, 5000),
            'price' => ['amount_minor' => (int) round((float) $price * 100), 'currency' => $currency],
            'bedrooms' => is_numeric($value('bedrooms')) ? (int) $value('bedrooms') : null,
            'bathrooms' => is_numeric($value('bathrooms')) ? (float) $value('bathrooms') : null,
            'interior_area' => is_numeric($value('area')) ? [
                'value' => (float) $value('area'),
                'unit' => str_contains($areaUnit, 'squarefeet') ? 'sq_ft' : 'sqm',
            ] : null,
            'address' => [
                'line_1' => $value('line_1'),
                'locality' => $value('locality'),
                'region' => $value('region'),
                'postal_code' => $value('postal_code'),
                'country_code' => strtoupper((string) $value('country_code', 'US')),
                'location_policy' => 'approximate',
            ],
            'features' => [],
            'amenity_slugs' => [],
            '_provider_status' => $status,
        ];
    }

    /** @param array<string, mixed> $canonical */
    private function addressCandidate(string $agencyId, array $canonical): ?Property
    {
        $normalized = collect(Arr::only($canonical['address'] ?? [], [
            'line_1', 'locality', 'region', 'postal_code', 'country_code',
        ]))->filter()->implode(', ');
        if ($normalized === '') {
            return null;
        }
        $addressId = Address::query()->where('agency_id', $agencyId)->where('normalized', $normalized)->value('id');

        return $addressId ? Property::query()->where('agency_id', $agencyId)->where('address_id', $addressId)->first() : null;
    }

    private function recordFailure(SyncJob $sync, ?string $sourceId, ?string $field, string $code, bool $retryable): void
    {
        DB::table('import_errors')->insert([
            'id' => (string) Str::uuid(),
            'sync_job_id' => $sync->id,
            'data_source_record_id' => $sourceId,
            'field' => $field,
            'code' => $code,
            'retryable' => $retryable,
            'detail' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $record */
    private function quarantineSource(
        ProviderConnection $connection,
        SyncJob $sync,
        FieldMapping $mapping,
        string $externalId,
        string $payloadHash,
        array $record,
        string $outcome,
    ): string {
        $existing = DB::table('data_source_records')
            ->where('provider_connection_id', $connection->id)
            ->where('resource', 'Property')
            ->where('external_record_id', $externalId)
            ->where('payload_hash', $payloadHash)->first();
        if ($existing) {
            return $existing->id;
        }
        $id = (string) Str::uuid();
        $this->insertSource($id, $connection, $sync, $mapping, $externalId, $payloadHash, $record, $outcome);

        return $id;
    }

    /** @param array<string, mixed> $record */
    private function insertSource(
        string $id,
        ProviderConnection $connection,
        SyncJob $sync,
        FieldMapping $mapping,
        string $externalId,
        string $payloadHash,
        array $record,
        string $outcome,
        ?string $propertyId = null,
        ?string $listingId = null,
    ): void {
        DB::table('data_source_records')->insert([
            'id' => $id,
            'provider_connection_id' => $connection->id,
            'sync_job_id' => $sync->id,
            'property_id' => $propertyId,
            'listing_id' => $listingId,
            'resource' => 'Property',
            'external_record_id' => $externalId,
            'payload_hash' => $payloadHash,
            'mapping_version' => $mapping->version,
            'rights_snapshot' => json_encode($connection->rights_snapshot, JSON_THROW_ON_ERROR),
            'provider_modified_at' => $this->providerModifiedAt(
                data_get($record, $mapping->fields['modified_at'] ?? 'ModificationTimestamp'),
            ),
            'outcome' => $outcome,
            'raw_envelope' => json_encode($record, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function providerModifiedAt(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
