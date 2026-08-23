<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AdminHealthController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $components = [
            'database' => $this->check(fn () => DB::select('select 1')),
            'cache' => $this->check(function (): void {
                $key = 'ops:admin-health:'.getmypid();
                Cache::put($key, 'ok', 10);
                if (Cache::pull($key) !== 'ok') {
                    throw new \RuntimeException('Cache verification failed.');
                }
            }),
            'media_storage' => $this->check(fn () => Storage::disk('listing_media')->exists('.healthcheck')),
            'worker' => $this->heartbeat('worker', (bool) config('operations.require_worker_heartbeat')),
            'scheduler' => $this->heartbeat('scheduler', (bool) config('operations.require_scheduler_heartbeat')),
            'queue' => $this->backlog(fn () => Queue::size(), (int) config('operations.queue_backlog_degraded')),
            'failed_jobs' => $this->databaseBacklog('failed_jobs', null, 0),
            'search_projection' => $this->databaseBacklog(
                'search_projection_outbox',
                'processed_at',
                (int) config('operations.search_backlog_degraded'),
            ),
        ];
        $status = collect($components)->contains(fn (array $component) => $component['status'] !== 'ok') ? 'degraded' : 'ok';

        return response()->json(['data' => [
            'status' => $status, 'version' => (string) config('operations.release_id'), 'checked_at' => now()->toISOString(),
            'components' => $components, 'request_id' => $request->attributes->get('request_id'),
        ]]);
    }

    /** @return array{status: string, backlog?: int} */
    private function check(callable $check, ?string $valueKey = null): array
    {
        try {
            $value = $check();

            return $valueKey ? ['status' => 'ok', $valueKey => (int) $value] : ['status' => 'ok'];
        } catch (Throwable) {
            return ['status' => 'degraded'];
        }
    }

    /** @return array{status: string, backlog?: int} */
    private function databaseBacklog(string $table, ?string $unprocessedColumn = null, int $degradedAbove = 0): array
    {
        return $this->backlog(function () use ($table, $unprocessedColumn): int {
            $query = DB::table($table);
            if ($unprocessedColumn) {
                $query->whereNull($unprocessedColumn);
            }

            return $query->count();
        }, $degradedAbove);
    }

    /** @return array{status: string, backlog?: int} */
    private function backlog(callable $count, int $degradedAbove): array
    {
        try {
            $backlog = (int) $count();

            return ['status' => $backlog > $degradedAbove ? 'degraded' : 'ok', 'backlog' => $backlog];
        } catch (Throwable) {
            return ['status' => 'degraded'];
        }
    }

    /** @return array{status: string, required: bool, age_seconds?: int} */
    private function heartbeat(string $component, bool $required): array
    {
        if (! $required) {
            return ['status' => 'ok', 'required' => false];
        }
        try {
            $heartbeat = Cache::get("ops:{$component}:heartbeat");
            if (! is_int($heartbeat)) {
                return ['status' => 'degraded', 'required' => true];
            }
            $age = max(0, now()->getTimestamp() - $heartbeat);

            return [
                'status' => $age <= (int) config('operations.heartbeat_ttl_seconds') ? 'ok' : 'degraded',
                'required' => true,
                'age_seconds' => $age,
            ];
        } catch (Throwable) {
            return ['status' => 'degraded', 'required' => true];
        }
    }
}
