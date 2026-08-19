<?php

namespace App\Http\Middleware;

use App\Domain\Administration\PlatformAuthorization;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformPermission
{
    public function __construct(private readonly PlatformAuthorization $authorization) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $authorized = $request->user() && $this->authorization->allows($request->user(), $permission);

        if (! $authorized) {
            return $this->denied($request);
        }

        return $next($request);
    }

    private function denied(Request $request): JsonResponse
    {
        return response()->json(['error' => [
            'code' => 'PLATFORM_PERMISSION_DENIED',
            'message' => 'You do not have permission to access platform operations.',
            'fields' => (object) [],
            'request_id' => $request->attributes->get('request_id'),
        ]], 403);
    }
}
