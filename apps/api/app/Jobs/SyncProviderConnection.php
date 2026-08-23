<?php

namespace App\Jobs;

use App\Domain\Integrations\IntegrationSyncService;
use App\Domain\Tenancy\TenantContext;
use App\Models\AgencyMember;
use App\Models\ProviderConnection;
use App\Models\SyncJob;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class SyncProviderConnection implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly string $connectionId,
        public readonly string $syncId,
        public readonly string $userId,
        public readonly string $agencyId,
    ) {
        $this->onQueue('integrations');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('integration:'.$this->connectionId))
            ->releaseAfter(30)->expireAfter($this->timeout + 60)];
    }

    public function handle(IntegrationSyncService $service, TenantContext $tenant): void
    {
        $membership = AgencyMember::query()->with(['agency', 'roles.permissions'])
            ->where('agency_id', $this->agencyId)
            ->where('user_id', $this->userId)
            ->where('status', 'active')->first();
        $user = User::query()->find($this->userId);
        if (! $membership || ! $user) {
            SyncJob::query()->whereKey($this->syncId)->update([
                'status' => 'failed',
                'failure_code' => 'SYNC_ACTOR_UNAVAILABLE',
                'completed_at' => now(),
            ]);
            ProviderConnection::query()->where('agency_id', $this->agencyId)
                ->whereKey($this->connectionId)->update(['last_sync_status' => 'failed']);

            return;
        }
        $connection = ProviderConnection::query()->where('agency_id', $this->agencyId)
            ->findOrFail($this->connectionId);
        $sync = SyncJob::query()->where('provider_connection_id', $connection->id)
            ->findOrFail($this->syncId);
        if (! in_array($sync->status, ['queued', 'failed'], true)) {
            return;
        }
        $request = Request::create('/internal/integration-sync', 'POST');
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('request_id', 'integration-sync:'.$sync->id);
        $request->headers->set('Agency-ID', $this->agencyId);
        $tenant->activate($membership);
        try {
            $service->run($request, $connection, $sync);
        } catch (\Throwable $exception) {
            report($exception);
            if ($this->attempts() >= $this->tries) {
                throw new RuntimeException('Provider synchronization exhausted its retry budget.', previous: $exception);
            }
            throw $exception;
        } finally {
            $tenant->clear();
        }
    }
}
