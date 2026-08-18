<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (! $this->context->membership()->hasPermission($permission)) {
            return response()->json([
                'error' => [
                    'code' => 'PERMISSION_DENIED',
                    'message' => 'You do not have permission to perform this action.',
                    'fields' => (object) [],
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ], 403);
        }

        return $next($request);
    }
}
