<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class AdminHealthController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $database = 'ok';
        try {
            DB::select('select 1');
        } catch (Throwable) {
            $database = 'degraded';
        }
        $components = $database === 'ok'
            ? [
                'database' => ['status' => 'ok'],
                'queue' => ['status' => 'ok', 'backlog' => (int) DB::table('jobs')->count()],
                'failed_jobs' => ['status' => DB::table('failed_jobs')->exists() ? 'degraded' : 'ok', 'backlog' => (int) DB::table('failed_jobs')->count()],
                'search_projection' => ['status' => 'ok', 'backlog' => (int) DB::table('search_projection_outbox')->whereNull('processed_at')->count()],
            ]
            : [
                'database' => ['status' => 'degraded'],
                'queue' => ['status' => 'unknown'],
                'failed_jobs' => ['status' => 'unknown'],
                'search_projection' => ['status' => 'unknown'],
            ];
        $status = collect($components)->contains(fn (array $component) => $component['status'] !== 'ok') ? 'degraded' : 'ok';

        return response()->json(['data' => [
            'status' => $status, 'version' => (string) config('app.version', '1'), 'checked_at' => now()->toISOString(),
            'components' => $components, 'request_id' => $request->attributes->get('request_id'),
        ]]);
    }
}
