<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class OperationsHealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'casaura-api',
            'release' => (string) config('operations.release_id'),
        ]);
    }

    public function ready(): JsonResponse
    {
        $checks = [
            'database' => fn () => DB::select('select 1'),
            'cache' => function (): void {
                $key = 'ops:readiness:'.getmypid();
                Cache::put($key, 'ok', 10);
                if (Cache::pull($key) !== 'ok') {
                    throw new RuntimeException('Cache read-after-write failed.');
                }
            },
            'media_storage' => fn () => Storage::disk('listing_media')->exists('.healthcheck'),
        ];

        foreach ($checks as $check) {
            try {
                $check();
            } catch (Throwable) {
                return $this->unavailable();
            }
        }

        if (! $this->freshHeartbeat('worker', (bool) config('operations.require_worker_heartbeat'))
            || ! $this->freshHeartbeat('scheduler', (bool) config('operations.require_scheduler_heartbeat'))) {
            return $this->unavailable();
        }

        return response()->json([
            'status' => 'ok',
            'service' => 'casaura-api',
            'release' => (string) config('operations.release_id'),
        ]);
    }

    private function unavailable(): JsonResponse
    {
        return response()->json([
            'status' => 'unavailable',
            'service' => 'casaura-api',
            'release' => (string) config('operations.release_id'),
        ], 503);
    }

    private function freshHeartbeat(string $component, bool $required): bool
    {
        if (! $required) {
            return true;
        }

        $heartbeat = Cache::get("ops:{$component}:heartbeat");

        return is_int($heartbeat)
            && $heartbeat >= now()->subSeconds((int) config('operations.heartbeat_ttl_seconds'))->getTimestamp();
    }
}
