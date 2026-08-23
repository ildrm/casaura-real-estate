<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\ApiException;
use App\Domain\Integrations\RealEstateDataProviderClient;
use App\Domain\Tenancy\AuditRecorder;
use App\Domain\Tenancy\FeatureResolver;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Jobs\SyncProviderConnection;
use App\Models\FieldMapping;
use App\Models\ProviderConnection;
use App\Models\RealEstateDataProvider;
use App\Models\SyncJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class IntegrationController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly FeatureResolver $features,
        private readonly AuditRecorder $audit,
        private readonly RealEstateDataProviderClient $providerClient,
    ) {}

    public function index(): JsonResponse
    {
        $this->ensureEnabled();

        return response()->json(['data' => ProviderConnection::query()
            ->where('agency_id', $this->tenant->id())
            ->with('provider')
            ->latest()->get()->map(fn (ProviderConnection $connection) => $this->connectionData($connection))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'provider' => ['required', Rule::in(['reso'])],
            'base_url' => ['required', 'url:https', 'max:500'],
            'token_url' => ['required', 'url:https', 'max:500'],
            'client_id' => ['required', 'string', 'max:255'],
            'secret_reference' => ['required', 'string', 'regex:/^[A-Za-z0-9_.-]+$/', 'max:255'],
            'resources' => ['required', 'array', 'min:1', 'max:10'],
            'resources.*' => ['string', 'max:120'],
            'rights' => ['required', 'array'],
            'rights.display' => ['required', 'boolean'],
            'rights.photos' => ['required', 'boolean'],
            'rights.attribution' => ['required', 'string', 'max:255'],
            'data_dictionary_version' => ['nullable', 'string', 'max:32'],
        ]);
        $provider = RealEstateDataProvider::query()->firstOrCreate(['key' => 'reso'], [
            'name' => 'RESO Web API',
            'adapter' => 'reso_odata',
            'protocol' => 'OData 4.01',
            'is_active' => true,
            'capabilities' => ['read', 'metadata', 'incremental'],
        ]);
        $connection = DB::transaction(function () use ($request, $validated, $provider): ProviderConnection {
            $connection = ProviderConnection::query()->create([
                'agency_id' => $this->tenant->id(),
                'provider_id' => $provider->id,
                'name' => $validated['name'],
                'base_url' => rtrim($validated['base_url'], '/').'/',
                'token_url' => $validated['token_url'],
                'client_id' => $validated['client_id'],
                'secret_reference' => $validated['secret_reference'],
                'resources' => array_values(array_unique($validated['resources'])),
                'rights_snapshot' => $validated['rights'],
                'data_dictionary_version' => $validated['data_dictionary_version'] ?? '2.0',
            ]);
            $this->audit->record($request, 'integration.connection_created', $connection, null, [
                'provider' => 'reso',
                'name' => $connection->name,
                'secret_reference' => $connection->secret_reference,
            ], $this->tenant->id());

            return $connection;
        });

        return response()->json(['data' => $this->connectionData($connection->load('provider'))], 201);
    }

    public function show(string $connection): JsonResponse
    {
        return response()->json(['data' => $this->connectionData($this->connection($connection)->load('provider'))]);
    }

    public function update(Request $request, string $connection): JsonResponse
    {
        $record = $this->connection($connection);
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'enabled' => ['sometimes', 'boolean'],
            'version' => ['required', 'integer', 'min:1'],
            'rights' => ['sometimes', 'array'],
        ]);
        if ((int) $validated['version'] !== $record->version) {
            throw new ApiException('INTEGRATION_VERSION_CONFLICT', 'The provider connection changed.', 409);
        }
        $record->fill([
            'name' => $validated['name'] ?? $record->name,
            'is_enabled' => $validated['enabled'] ?? $record->is_enabled,
            'rights_snapshot' => $validated['rights'] ?? $record->rights_snapshot,
            'version' => $record->version + 1,
        ])->save();
        $this->audit->record($request, 'integration.connection_updated', $record, null, [
            'name' => $record->name, 'enabled' => $record->is_enabled, 'version' => $record->version,
        ], $this->tenant->id());

        return response()->json(['data' => $this->connectionData($record->load('provider'))]);
    }

    public function destroy(Request $request, string $connection): JsonResponse
    {
        $record = $this->connection($connection);
        $record->update(['is_enabled' => false, 'version' => $record->version + 1]);
        $this->audit->record($request, 'integration.connection_disabled', $record, null, [
            'enabled' => false,
        ], $this->tenant->id());

        return response()->json(null, 204);
    }

    public function mappings(string $connection): JsonResponse
    {
        $record = $this->connection($connection);

        return response()->json(['data' => FieldMapping::query()
            ->where('provider_connection_id', $record->id)->orderByDesc('version')->get()]);
    }

    public function metadata(string $connection): JsonResponse
    {
        $record = $this->connection($connection);

        return response()->json(['data' => [
            ...$this->providerClient->metadata($record),
            'data_dictionary_version' => $record->data_dictionary_version,
        ]]);
    }

    public function storeMapping(Request $request, string $connection): JsonResponse
    {
        $record = $this->connection($connection);
        $validated = $request->validate([
            'resource' => ['required', 'string', 'max:120'],
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => ['required', 'string', 'max:160'],
        ]);
        $version = ((int) FieldMapping::query()
            ->where('provider_connection_id', $record->id)
            ->where('resource', $validated['resource'])->max('version')) + 1;
        $mapping = FieldMapping::query()->create([
            'provider_connection_id' => $record->id,
            'resource' => $validated['resource'],
            'version' => $version,
            'fields' => $validated['fields'],
            'created_by_user_id' => $request->user()->id,
            'activated_at' => now(),
        ]);
        $this->audit->record($request, 'integration.mapping_created', $mapping, null, [
            'resource' => $mapping->resource, 'version' => $mapping->version,
        ], $this->tenant->id());

        return response()->json(['data' => $mapping], 201);
    }

    public function syncs(string $connection): JsonResponse
    {
        $record = $this->connection($connection);

        return response()->json(['data' => SyncJob::query()
            ->where('provider_connection_id', $record->id)->latest()->limit(100)->get()]);
    }

    public function startSync(Request $request, string $connection): JsonResponse
    {
        $record = $this->connection($connection);
        if (! $record->is_enabled) {
            throw new ApiException('INTEGRATION_DISABLED', 'The provider connection is disabled.', 409);
        }
        $validated = $request->validate(['mode' => ['required', Rule::in(['full', 'incremental'])]]);
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '') {
            throw new ApiException('IDEMPOTENCY_KEY_REQUIRED', 'An Idempotency-Key header is required.', 422);
        }
        $hash = hash('sha256', json_encode($validated, JSON_THROW_ON_ERROR));
        $existing = SyncJob::query()->where('provider_connection_id', $record->id)
            ->where('idempotency_key', $key)->first();
        if ($existing) {
            if (! hash_equals($existing->payload_hash, $hash)) {
                throw new ApiException('IDEMPOTENCY_CONFLICT', 'The idempotency key was used with another payload.', 409);
            }

            return response()->json(['data' => $existing]);
        }
        $sync = DB::transaction(function () use ($record, $validated, $key, $hash): SyncJob {
            ProviderConnection::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            if (SyncJob::query()->where('provider_connection_id', $record->id)
                ->whereIn('status', ['queued', 'running'])->exists()) {
                throw new ApiException('INTEGRATION_SYNC_ACTIVE', 'A synchronization is already active for this connection.', 409);
            }
            $cursor = $validated['mode'] === 'incremental'
                ? SyncJob::query()->where('provider_connection_id', $record->id)
                    ->whereIn('status', ['completed', 'partial'])->latest('completed_at')->value('end_cursor')
                : null;

            return SyncJob::query()->create([
                'provider_connection_id' => $record->id,
                'mode' => $validated['mode'],
                'status' => 'queued',
                'idempotency_key' => $key,
                'payload_hash' => $hash,
                'start_cursor' => $cursor,
            ]);
        });
        SyncProviderConnection::dispatch($record->id, $sync->id, (string) $request->user()->id, $this->tenant->id());

        return response()->json(['data' => $sync->refresh()], 202);
    }

    public function showSync(string $sync): JsonResponse
    {
        $record = SyncJob::query()->whereHas('connection', fn ($query) => $query
            ->where('agency_id', $this->tenant->id()))->findOrFail($sync);

        return response()->json(['data' => $record]);
    }

    public function importErrors(): JsonResponse
    {
        return response()->json(['data' => DB::table('import_errors')
            ->join('sync_jobs', 'sync_jobs.id', '=', 'import_errors.sync_job_id')
            ->join('provider_connections', 'provider_connections.id', '=', 'sync_jobs.provider_connection_id')
            ->where('provider_connections.agency_id', $this->tenant->id())
            ->select('import_errors.id', 'import_errors.field', 'import_errors.code', 'import_errors.retryable', 'import_errors.resolved_at', 'import_errors.created_at')
            ->latest('import_errors.created_at')->limit(100)->get()]);
    }

    private function connection(string $id): ProviderConnection
    {
        $this->ensureEnabled();

        return ProviderConnection::query()->where('agency_id', $this->tenant->id())->findOrFail($id);
    }

    private function ensureEnabled(): void
    {
        $this->features->ensureEnabled('mls', $this->tenant->agency());
    }

    /** @return array<string, mixed> */
    private function connectionData(ProviderConnection $connection): array
    {
        return [
            'id' => $connection->id,
            'provider' => $connection->provider?->key ?? 'reso',
            'name' => $connection->name,
            'base_url' => $connection->base_url,
            'token_url' => $connection->token_url,
            'secret_reference' => $connection->secret_reference,
            'resources' => $connection->resources,
            'rights' => $connection->rights_snapshot,
            'data_dictionary_version' => $connection->data_dictionary_version,
            'enabled' => $connection->is_enabled,
            'version' => $connection->version,
            'last_sync_status' => $connection->last_sync_status,
            'last_synced_at' => $connection->last_synced_at,
            'created_at' => $connection->created_at,
            'updated_at' => $connection->updated_at,
        ];
    }
}
