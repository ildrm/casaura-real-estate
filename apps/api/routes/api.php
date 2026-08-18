<?php

use App\Http\Controllers\Api\V1\AgencyController;
use App\Http\Controllers\Api\V1\AgencyMemberController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\FeatureFlagController;
use App\Http\Controllers\Api\V1\MeController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', function () {
        DB::select('select 1');

        return response()->json([
            'status' => 'ok',
            'service' => 'casaura-api',
            'version' => '1',
        ]);
    })->name('api.v1.health');

    Route::middleware('throttle:auth')->group(function (): void {
        Route::post('/auth/register', [AuthController::class, 'register']);
        Route::post('/auth/register-agency', [AuthController::class, 'registerAgency']);
        Route::post('/auth/login', [AuthController::class, 'login']);
    });

    Route::get('/agencies/{agency}', [AgencyController::class, 'show']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/me', MeController::class);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::middleware('tenant')->group(function (): void {
            Route::get('/agency', [AgencyController::class, 'current']);
            Route::patch('/agency', [AgencyController::class, 'update'])
                ->middleware('permission:agency.manage_profile');
            Route::get('/agency/members', [AgencyMemberController::class, 'index'])
                ->middleware('permission:agency.manage_members');
            Route::get('/agency/feature-flags', [FeatureFlagController::class, 'index']);
        });
    });
});
