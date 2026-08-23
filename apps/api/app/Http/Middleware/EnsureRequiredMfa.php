<?php

namespace App\Http\Middleware;

use App\Domain\ApiException;
use App\Domain\Identity\IdentityPolicy;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRequiredMfa
{
    public function __construct(private readonly IdentityPolicy $identity) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || ! $this->identity->requiresMfa($user)) {
            return $next($request);
        }

        if (! $user->mfa_confirmed_at || ! $user->mfa_secret) {
            throw new ApiException('MFA_SETUP_REQUIRED', 'Set up multi-factor authentication before continuing.', 403);
        }

        $token = $user->currentAccessToken();
        $asserted = $token
            ? $user->tokenCan('mfa')
            : (int) $request->session()->get('identity.mfa_security_version') === $user->security_version;

        if (! $asserted) {
            throw new ApiException('MFA_REQUIRED', 'Complete multi-factor authentication before continuing.', 403);
        }

        return $next($request);
    }
}
