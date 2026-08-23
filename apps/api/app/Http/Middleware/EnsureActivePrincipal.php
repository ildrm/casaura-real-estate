<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\TransientToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureActivePrincipal
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $persisted = $user instanceof User
            ? User::query()->whereKey($user->getAuthIdentifier())->first(['id', 'status', 'security_version'])
            : null;

        if (! $persisted || $persisted->status !== 'active') {
            return $this->denied($request);
        }

        $token = $user->currentAccessToken();
        if ($token && ! $token instanceof TransientToken && $token->exists === true) {
            if ((int) $token->security_version !== $persisted->security_version) {
                $token->delete();

                return $this->revoked($request);
            }
        } elseif (($token instanceof TransientToken || ! $token) && $request->hasSession()) {
            $assertedVersion = $request->session()->get('identity.security_version');
            if (! is_int($assertedVersion) || $assertedVersion !== $persisted->security_version) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return $this->revoked($request);
            }
        }

        return $next($request);
    }

    private function denied(Request $request): JsonResponse
    {
        return response()->json(['error' => [
            'code' => 'ACCOUNT_ACCESS_DENIED',
            'message' => 'This account is not available.',
            'fields' => (object) [],
            'request_id' => $request->attributes->get('request_id'),
        ]], 403);
    }

    private function revoked(Request $request): JsonResponse
    {
        return response()->json(['error' => [
            'code' => 'SESSION_REVOKED',
            'message' => 'Your session is no longer valid. Sign in again.',
            'fields' => (object) [],
            'request_id' => $request->attributes->get('request_id'),
        ]], 401);
    }
}
