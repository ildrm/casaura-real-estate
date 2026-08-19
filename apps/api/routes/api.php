<?php

use App\Http\Controllers\Api\V1\AbuseReportController;
use App\Http\Controllers\Api\V1\AccountCollaborationController;
use App\Http\Controllers\Api\V1\AdminAuditController;
use App\Http\Controllers\Api\V1\AdminFeatureFlagController;
use App\Http\Controllers\Api\V1\AdminHealthController;
use App\Http\Controllers\Api\V1\AdminModerationController;
use App\Http\Controllers\Api\V1\AdminRoleController;
use App\Http\Controllers\Api\V1\AdminSettingController;
use App\Http\Controllers\Api\V1\AgencyAnalyticsController;
use App\Http\Controllers\Api\V1\AgencyController;
use App\Http\Controllers\Api\V1\AgencyMemberController;
use App\Http\Controllers\Api\V1\AgencyTeamController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CollaborationAnalyticsController;
use App\Http\Controllers\Api\V1\ConsumerEngagementController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\FeatureFlagController;
use App\Http\Controllers\Api\V1\LeadController;
use App\Http\Controllers\Api\V1\ListingController;
use App\Http\Controllers\Api\V1\ListingMediaController;
use App\Http\Controllers\Api\V1\ListingWorkflowController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\NewsletterCampaignController;
use App\Http\Controllers\Api\V1\NewsletterSubscriptionController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OpeningHoursController;
use App\Http\Controllers\Api\V1\PropertyCatalogController;
use App\Http\Controllers\Api\V1\PublicLeadController;
use App\Http\Controllers\Api\V1\PublicListingController;
use App\Http\Controllers\Api\V1\PublicMediaController;
use App\Http\Controllers\Api\V1\PublicSearchController;
use App\Http\Controllers\Api\V1\PublicStorefrontController;
use App\Http\Controllers\Api\V1\ReminderController;
use App\Http\Controllers\Api\V1\ViewingController;
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
    Route::post('/public/listings/{listing}/leads', [PublicLeadController::class, 'store'])
        ->middleware('throttle:lead');
    Route::get('/public/agencies/{agency}', PublicStorefrontController::class);
    Route::post('/public/agencies/{agency}/newsletter/subscriptions', [NewsletterSubscriptionController::class, 'store'])
        ->middleware('throttle:newsletter');
    Route::delete('/public/newsletter/subscriptions/{token}', [NewsletterSubscriptionController::class, 'destroy'])
        ->middleware('throttle:newsletter');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/me', MeController::class);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/account/collaboration', AccountCollaborationController::class);
        Route::get('/conversations/{conversation}/messages', [ConversationController::class, 'messages']);
        Route::post('/conversations/{conversation}/messages', [ConversationController::class, 'send'])
            ->middleware('throttle:engagement');
        Route::get('/viewings/{viewing}/calendar', [ViewingController::class, 'calendar']);
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::patch('/notifications/{notification}', [NotificationController::class, 'read']);
        Route::post('/public/listings/{listing}/reports', [AbuseReportController::class, 'store'])
            ->middleware('throttle:report');

        Route::prefix('admin')->group(function (): void {
            Route::get('/moderation-cases', [AdminModerationController::class, 'index'])
                ->middleware('platform_permission:comment.moderate');
            Route::patch('/moderation-cases/{case}', [AdminModerationController::class, 'update'])
                ->middleware('platform_permission:comment.moderate');
            Route::get('/settings', [AdminSettingController::class, 'index'])
                ->middleware('platform_permission:platform.settings');
            Route::patch('/settings/{namespace}/{key}', [AdminSettingController::class, 'update'])
                ->middleware('platform_permission:platform.settings');
            Route::get('/feature-flags', [AdminFeatureFlagController::class, 'index'])
                ->middleware('platform_permission:platform.settings');
            Route::put('/feature-flags/{flag}/overrides', [AdminFeatureFlagController::class, 'updateOverride'])
                ->middleware('platform_permission:platform.settings');
            Route::delete('/feature-flags/{flag}/overrides/{override}', [AdminFeatureFlagController::class, 'destroyOverride'])
                ->middleware('platform_permission:platform.settings');
            Route::get('/roles', [AdminRoleController::class, 'index'])
                ->middleware('platform_permission:platform.settings');
            Route::post('/roles', [AdminRoleController::class, 'store'])
                ->middleware('platform_permission:platform.settings');
            Route::patch('/roles/{role}', [AdminRoleController::class, 'update'])
                ->middleware('platform_permission:platform.settings');
            Route::delete('/roles/{role}', [AdminRoleController::class, 'destroy'])
                ->middleware('platform_permission:platform.settings');
            Route::get('/audit-logs', AdminAuditController::class)
                ->middleware('platform_permission:audit.view');
            Route::get('/health', AdminHealthController::class)
                ->middleware('platform_permission:audit.view');
        });
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
            Route::get('/leads', [LeadController::class, 'index'])->middleware('permission:lead.manage');
            Route::get('/leads/{lead}', [LeadController::class, 'show'])->middleware('permission:lead.manage');
            Route::patch('/leads/{lead}', [LeadController::class, 'update'])->middleware('permission:lead.manage');
            Route::get('/viewings', [ViewingController::class, 'index'])->middleware('permission:lead.manage');
            Route::post('/viewings', [ViewingController::class, 'store'])->middleware('permission:lead.manage');
            Route::patch('/viewings/{viewing}', [ViewingController::class, 'update'])->middleware('permission:lead.manage');
            Route::get('/reminders', [ReminderController::class, 'index'])->middleware('permission:lead.manage');
            Route::post('/reminders', [ReminderController::class, 'store'])->middleware('permission:lead.manage');
            Route::patch('/reminders/{reminder}', [ReminderController::class, 'update'])->middleware('permission:lead.manage');
            Route::get('/agency/analytics/collaboration', CollaborationAnalyticsController::class)
                ->middleware('permission:analytics.view');
            Route::get('/agency/opening-hours', [OpeningHoursController::class, 'index']);
            Route::put('/agency/opening-hours', [OpeningHoursController::class, 'update'])
                ->middleware('permission:agency.manage_profile');
            Route::get('/agency/team', [AgencyTeamController::class, 'index'])
                ->middleware('permission:agency.manage_members');
            Route::post('/agency/team', [AgencyTeamController::class, 'store'])
                ->middleware('permission:agency.manage_members');
            Route::patch('/agency/team/{member}', [AgencyTeamController::class, 'update'])
                ->middleware('permission:agency.manage_members');
            Route::get('/agency/newsletter/campaigns', [NewsletterCampaignController::class, 'index'])
                ->middleware('permission:agency.manage_profile');
            Route::post('/agency/newsletter/campaigns', [NewsletterCampaignController::class, 'store'])
                ->middleware('permission:agency.manage_profile');
            Route::patch('/agency/newsletter/campaigns/{campaign}', [NewsletterCampaignController::class, 'update'])
                ->middleware('permission:agency.manage_profile');
            Route::post('/agency/newsletter/campaigns/{campaign}/send', [NewsletterCampaignController::class, 'send'])
                ->middleware('permission:agency.manage_profile');
            Route::get('/agency/analytics', AgencyAnalyticsController::class)
                ->middleware('permission:analytics.view');
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
