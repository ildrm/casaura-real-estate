<?php

namespace App\Http\Middleware;

use App\Domain\ApiException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureVerifiedIdentity
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->hasVerifiedEmail()) {
            throw new ApiException(
                'EMAIL_VERIFICATION_REQUIRED',
                'Verify your email address before continuing.',
                403,
            );
        }

        return $next($request);
    }
}
