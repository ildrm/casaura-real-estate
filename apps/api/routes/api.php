<?php

use App\Http\Controllers\Api\V1\AgencyController;
use App\Http\Controllers\Api\V1\AgencyMemberController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ConsumerEngagementController;
use App\Http\Controllers\Api\V1\FeatureFlagController;
use App\Http\Controllers\Api\V1\ListingController;
use App\Http\Controllers\Api\V1\ListingMediaController;
use App\Http\Controllers\Api\V1\ListingWorkflowController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PropertyCatalogController;
use App\Http\Controllers\Api\V1\PublicListingController;
use App\Http\Controllers\Api\V1\PublicMediaController;
use App\Http\Controllers\Api\V1\PublicSearchController;
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
    Route::middleware('throttle:search')->group(function (): void {
        Route::get('/public/search', PublicSearchController::class);
        Route::get('/public/listings/{listing}', [PublicListingController::class, 'show']);
        Route::get('/public/media/{media}/{kind}', PublicMediaController::class)
            ->where('kind', 'thumbnail|display');
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/me', MeController::class);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::middleware('throttle:engagement')->prefix('account')->group(function (): void {
            Route::get('/engagements', [ConsumerEngagementController::class, 'index']);
            Route::put('/favorites/{listing}', [ConsumerEngagementController::class, 'favorite']);
            Route::delete('/favorites/{listing}', [ConsumerEngagementController::class, 'unfavorite']);
            Route::put('/reactions/{listing}', [ConsumerEngagementController::class, 'react']);
            Route::delete('/reactions/{listing}', [ConsumerEngagementController::class, 'unreact']);
        });

        Route::middleware('tenant')->group(function (): void {
            Route::get('/agency', [AgencyController::class, 'current']);
            Route::patch('/agency', [AgencyController::class, 'update'])
                ->middleware('permission:agency.manage_profile');
            Route::get('/agency/members', [AgencyMemberController::class, 'index'])
                ->middleware('permission:agency.manage_members');
            Route::get('/agency/feature-flags', [FeatureFlagController::class, 'index']);

            Route::get('/property-catalog', PropertyCatalogController::class);
            Route::get('/listings', [ListingController::class, 'index'])
                ->middleware('permission:listing.view');
            Route::post('/listings', [ListingController::class, 'store'])
                ->middleware('permission:listing.create');
            Route::get('/listings/{listing}', [ListingController::class, 'show'])
                ->middleware('permission:listing.view');
            Route::patch('/listings/{listing}', [ListingController::class, 'update'])
                ->middleware('permission:listing.update');
            Route::delete('/listings/{listing}', [ListingController::class, 'destroy'])
                ->middleware('permission:listing.delete');
            Route::post('/listings/{listing}/submit', [ListingWorkflowController::class, 'submit'])
                ->middleware('permission:listing.update');
            Route::post('/listings/{listing}/publish', [ListingWorkflowController::class, 'publish'])
                ->middleware('permission:listing.publish');
            Route::post('/listings/{listing}/request-changes', [ListingWorkflowController::class, 'requestChanges'])
                ->middleware('permission:listing.publish');
            Route::post('/listings/{listing}/withdraw', [ListingWorkflowController::class, 'withdraw'])
                ->middleware('permission:listing.publish');
            Route::get('/listings/{listing}/media', [ListingMediaController::class, 'index'])
                ->middleware('permission:listing.view');
            Route::post('/listings/{listing}/media', [ListingMediaController::class, 'store'])
                ->middleware(['permission:media.manage', 'throttle:media']);
            Route::patch('/listings/{listing}/media/order', [ListingMediaController::class, 'reorder'])
                ->middleware('permission:media.manage');
            Route::delete('/listings/{listing}/media/{media}', [ListingMediaController::class, 'destroy'])
                ->middleware('permission:media.manage');
        });
    });
});
