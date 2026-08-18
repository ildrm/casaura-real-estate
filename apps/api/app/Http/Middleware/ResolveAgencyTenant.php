<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveAgencyTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $agencyId = $request->header('Agency-ID');

        if (! is_string($agencyId) || $agencyId === '') {
            return $this->error($request, 'TENANT_REQUIRED', 'Select an agency for this request.', 422);
        }

        $membership = $request->user()?->memberships()
            ->with(['agency', 'roles.permissions'])
            ->where('agency_id', $agencyId)
            ->where('status', 'active')
            ->first();

        if (! $membership || $membership->agency->status !== 'active') {
            return $this->error($request, 'TENANT_ACCESS_DENIED', 'You do not have access to this agency.', 403);
        }

        $this->context->activate($membership);
        $request->attributes->set('agency', $membership->agency);
        $request->attributes->set('agency_membership', $membership);

        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }

    private function error(Request $request, string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'fields' => (object) [],
                'request_id' => $request->attributes->get('request_id'),
            ],
        ], $status);
    }
}
