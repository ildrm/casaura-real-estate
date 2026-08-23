<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AddRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);
        $incoming = $request->header('Request-ID');
        $requestId = is_string($incoming) && Str::isUuid($incoming)
            ? $incoming
            : (string) Str::uuid();

        $request->attributes->set('request_id', $requestId);
        Log::shareContext([
            'request_id' => $requestId,
            'release_id' => (string) config('operations.release_id'),
        ]);

        try {
            $response = $next($request);
            $response->headers->set('Request-ID', $requestId);
            $response->headers->set('Release-ID', (string) config('operations.release_id'));
            Log::info('http.request_completed', [
                'method' => $request->method(),
                'route' => $request->route()?->getName() ?? $request->path(),
                'status' => $response->getStatusCode(),
                'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
                'user_id' => $request->user()?->getAuthIdentifier(),
                'agency_id' => $request->attributes->get('agency_id'),
            ]);

            return $response;
        } catch (Throwable $exception) {
            Log::error('http.request_failed', [
                'method' => $request->method(),
                'route' => $request->route()?->getName() ?? $request->path(),
                'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
                'exception' => $exception::class,
            ]);
            throw $exception;
        } finally {
            Log::flushSharedContext();
        }
    }
}
