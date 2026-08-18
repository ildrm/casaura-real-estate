<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AddRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->header('Request-ID');
        $requestId = is_string($incoming) && Str::isUuid($incoming)
            ? $incoming
            : (string) Str::uuid();

        $request->attributes->set('request_id', $requestId);
        $response = $next($request);
        $response->headers->set('Request-ID', $requestId);

        return $response;
    }
}
