<?php

use App\Domain\ApiException;
use App\Domain\Listings\ListingException;
use App\Domain\Search\SearchException;
use App\Http\Middleware\AddRequestId;
use App\Http\Middleware\EnsureActivePrincipal;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsurePlatformPermission;
use App\Http\Middleware\EnsureRequiredMfa;
use App\Http\Middleware\EnsureVerifiedIdentity;
use App\Http\Middleware\ResolveAgencyTenant;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands()
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->append(AddRequestId::class);
        $middleware->alias([
            'active_principal' => EnsureActivePrincipal::class,
            'verified_identity' => EnsureVerifiedIdentity::class,
            'required_mfa' => EnsureRequiredMfa::class,
            'tenant' => ResolveAgencyTenant::class,
            'permission' => EnsurePermission::class,
            'platform_permission' => EnsurePlatformPermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => 'VALIDATION_FAILED',
                    'message' => 'The request contains invalid data.',
                    'fields' => $exception->errors(),
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ], 422);
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Authentication is required.',
                    'fields' => (object) [],
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ], 401);
        });

        $exceptions->render(function (ListingException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'error' => array_merge([
                    'code' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                    'fields' => (object) [],
                    'request_id' => $request->attributes->get('request_id'),
                ], $exception->context),
            ], $exception->status);
        });

        $exceptions->render(function (ApiException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'error' => array_merge([
                    'code' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                    'fields' => (object) [],
                    'request_id' => $request->attributes->get('request_id'),
                ], $exception->context),
            ], $exception->status);
        });

        $exceptions->render(function (SearchException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                    'fields' => (object) [],
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ], $exception->status);
        });

        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if (! $request->is('api/*') || $exception->getStatusCode() < 400) {
                return null;
            }

            $status = $exception->getStatusCode();
            $message = match ($status) {
                403 => 'This action is not allowed.',
                404 => 'The requested resource was not found.',
                429 => 'Too many requests. Please try again later.',
                default => 'The request could not be completed.',
            };

            return response()->json([
                'error' => [
                    'code' => match ($status) {
                        403 => 'FORBIDDEN',
                        404 => 'NOT_FOUND',
                        429 => 'RATE_LIMITED',
                        default => 'HTTP_ERROR',
                    },
                    'message' => $message,
                    'fields' => (object) [],
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ], $status);
        });
    })->create();
