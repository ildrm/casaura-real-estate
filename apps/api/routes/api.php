<?php

use App\Http\Controllers\Api\V1\AbuseReportController;
use App\Http\Controllers\Api\V1\AccountCollaborationController;
use App\Http\Controllers\Api\V1\AdminAiSafetyController;
use App\Http\Controllers\Api\V1\AdminAuditController;
use App\Http\Controllers\Api\V1\AdminFeatureFlagController;
use App\Http\Controllers\Api\V1\AdminHealthController;
use App\Http\Controllers\Api\V1\AdminModerationController;
use App\Http\Controllers\Api\V1\AdminPromotionPolicyController;
use App\Http\Controllers\Api\V1\AdminRoleController;
use App\Http\Controllers\Api\V1\AdminSettingController;
use App\Http\Controllers\Api\V1\AdvancedMarketplaceController;
use App\Http\Controllers\Api\V1\AgencyAnalyticsController;
use App\Http\Controllers\Api\V1\AgencyController;
use App\Http\Controllers\Api\V1\AgencyMemberController;
use App\Http\Controllers\Api\V1\AgencyTeamController;
use App\Http\Controllers\Api\V1\AiController;
use App\Http\Controllers\Api\V1\AiListingController;
use App\Http\Controllers\Api\V1\AiSessionController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BillingController;
use App\Http\Controllers\Api\V1\CollaborationAnalyticsController;
use App\Http\Controllers\Api\V1\CollectionController;
use App\Http\Controllers\Api\V1\ComparisonController;
use App\Http\Controllers\Api\V1\ConsumerEngagementController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\DuplicateCandidateController;
use App\Http\Controllers\Api\V1\FeatureFlagController;
use App\Http\Controllers\Api\V1\IdentityController;
use App\Http\Controllers\Api\V1\IntegrationController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\LeadController;
use App\Http\Controllers\Api\V1\ListingController;
use App\Http\Controllers\Api\V1\ListingMediaController;
use App\Http\Controllers\Api\V1\ListingWorkflowController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MfaController;
use App\Http\Controllers\Api\V1\NewsletterCampaignController;
use App\Http\Controllers\Api\V1\NewsletterSubscriptionController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OpeningHoursController;
use App\Http\Controllers\Api\V1\OperationsHealthController;
use App\Http\Controllers\Api\V1\PrivacyRequestController;
use App\Http\Controllers\Api\V1\PromotionCampaignController;
use App\Http\Controllers\Api\V1\PropertyCatalogController;
use App\Http\Controllers\Api\V1\PublicDiscoveryController;
use App\Http\Controllers\Api\V1\PublicLeadController;
use App\Http\Controllers\Api\V1\PublicListingController;
use App\Http\Controllers\Api\V1\PublicMediaController;
use App\Http\Controllers\Api\V1\PublicSearchController;
use App\Http\Controllers\Api\V1\PublicSponsoredListingController;
use App\Http\Controllers\Api\V1\PublicStorefrontController;
use App\Http\Controllers\Api\V1\ReminderController;
use App\Http\Controllers\Api\V1\StripeWebhookController;
use App\Http\Controllers\Api\V1\ViewingController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health/live', [OperationsHealthController::class, 'live'])->name('api.v1.health.live');
    Route::get('/health/ready', [OperationsHealthController::class, 'ready'])->name('api.v1.health.ready');
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
        Route::post('/auth/forgot-password', [IdentityController::class, 'forgotPassword']);
        Route::post('/auth/reset-password', [IdentityController::class, 'resetPassword']);
        Route::post('/auth/mfa/challenge', [MfaController::class, 'challenge']);
        Route::post('/auth/invitations/{token}/accept', [InvitationController::class, 'accept']);
    });

    Route::get('/agencies/{agency}', [AgencyController::class, 'show']);
    Route::middleware('throttle:search')->group(function (): void {
        Route::get('/public/search', PublicSearchController::class);
        Route::get('/public/discovery', PublicDiscoveryController::class);
        Route::get('/public/listings/{listing}', [PublicListingController::class, 'show']);
        Route::get('/public/listings/{listing}/recommendations', [AdvancedMarketplaceController::class, 'recommendations']);
        Route::get('/public/compare', [ComparisonController::class, 'compare']);
        Route::get('/public/map-layers', [AdvancedMarketplaceController::class, 'mapLayers']);
        Route::get('/public/market-analytics', [AdvancedMarketplaceController::class, 'marketAnalytics']);
        Route::get('/public/media/{media}/{kind}', PublicMediaController::class)
            ->where('kind', 'thumbnail|display');
    });
    Route::post('/public/listings/{listing}/leads', [PublicLeadController::class, 'store'])
        ->middleware('throttle:lead');
    Route::post('/webhooks/stripe', StripeWebhookController::class)->middleware('throttle:webhooks');
    Route::get('/public/sponsored-listings', PublicSponsoredListingController::class)
        ->middleware('throttle:search');
    Route::middleware('throttle:ai')->group(function (): void {
        Route::post('/ai/search', [AiController::class, 'search']);
        Route::post('/ai/comparisons', [AiController::class, 'comparison']);
    });
    Route::get('/public/agencies/{agency}', PublicStorefrontController::class);
    Route::post('/public/agencies/{agency}/newsletter/subscriptions', [NewsletterSubscriptionController::class, 'store'])
        ->middleware('throttle:newsletter');
    Route::delete('/public/newsletter/subscriptions/{token}', [NewsletterSubscriptionController::class, 'destroy'])
        ->middleware('throttle:newsletter');

    Route::middleware(['auth:sanctum', 'active_principal'])->group(function (): void {
        Route::get('/me', MeController::class);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/email/verification-notification', [IdentityController::class, 'resendVerification'])
            ->middleware('throttle:auth');
        Route::get('/auth/email/verify/{id}/{hash}', [IdentityController::class, 'verifyEmail'])
            ->middleware(['signed', 'throttle:auth'])
            ->name('verification.verify');
        Route::middleware('verified_identity')->group(function (): void {
            Route::post('/auth/mfa/setup', [MfaController::class, 'setup']);
            Route::post('/auth/mfa/confirm', [MfaController::class, 'confirm']);
            Route::delete('/auth/mfa', [MfaController::class, 'destroy']);
        });

        Route::middleware(['verified_identity', 'required_mfa'])->group(function (): void {
            Route::get('/account/collaboration', AccountCollaborationController::class);
            Route::get('/conversations/{conversation}/messages', [ConversationController::class, 'messages']);
            Route::post('/conversations/{conversation}/messages', [ConversationController::class, 'send'])
                ->middleware('throttle:engagement');
            Route::get('/viewings/{viewing}/calendar', [ViewingController::class, 'calendar']);
            Route::get('/notifications', [NotificationController::class, 'index']);
            Route::patch('/notifications/{notification}', [NotificationController::class, 'read']);
            Route::get('/account/privacy/requests', [PrivacyRequestController::class, 'index']);
            Route::post('/account/privacy/requests', [PrivacyRequestController::class, 'store'])
                ->middleware('throttle:auth');
            Route::get('/account/privacy/requests/{privacyRequest}/download', [PrivacyRequestController::class, 'download']);
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
                Route::get('/ai-safety-events', AdminAiSafetyController::class)
                    ->middleware('platform_permission:audit.view');
                Route::get('/promotion-policies', [AdminPromotionPolicyController::class, 'index'])
                    ->middleware('platform_permission:platform.settings');
                Route::post('/promotion-policies', [AdminPromotionPolicyController::class, 'store'])
                    ->middleware('platform_permission:platform.settings');
                Route::patch('/promotion-policies/{policy}', [AdminPromotionPolicyController::class, 'update'])
                    ->middleware('platform_permission:platform.settings');
            });
            Route::middleware('throttle:engagement')->prefix('account')->group(function (): void {
                Route::get('/engagements', [ConsumerEngagementController::class, 'index']);
                Route::put('/favorites/{listing}', [ConsumerEngagementController::class, 'favorite']);
                Route::delete('/favorites/{listing}', [ConsumerEngagementController::class, 'unfavorite']);
                Route::put('/reactions/{listing}', [ConsumerEngagementController::class, 'react']);
                Route::delete('/reactions/{listing}', [ConsumerEngagementController::class, 'unreact']);
                Route::get('/collections', [CollectionController::class, 'index']);
                Route::post('/collections', [CollectionController::class, 'store']);
                Route::get('/collections/{collection}', [CollectionController::class, 'show']);
                Route::patch('/collections/{collection}', [CollectionController::class, 'update']);
                Route::delete('/collections/{collection}', [CollectionController::class, 'destroy']);
                Route::put('/collections/{collection}/items', [CollectionController::class, 'addItem']);
                Route::delete('/collections/{collection}/items', [CollectionController::class, 'removeItem']);
                Route::patch('/collections/{collection}/items', [CollectionController::class, 'reorder']);
                Route::post('/collections/{collection}/members', [CollectionController::class, 'invite']);
                Route::delete('/collections/{collection}/members/{user}', [CollectionController::class, 'revoke']);
                Route::post('/collection-invitations/{token}/accept', [CollectionController::class, 'acceptInvitation']);
                Route::get('/comparisons', [ComparisonController::class, 'index']);
                Route::post('/comparisons', [ComparisonController::class, 'store']);
                Route::delete('/comparisons/{comparison}', [ComparisonController::class, 'destroy']);
                Route::get('/ai-sessions', [AiSessionController::class, 'index']);
                Route::delete('/ai-sessions/{session}', [AiSessionController::class, 'destroy']);
                Route::post('/ai-generations/{generation}/feedback', [AiSessionController::class, 'feedback']);
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
                Route::post('/agency/team/{member}/invitation', [AgencyTeamController::class, 'resendInvitation'])
                    ->middleware('permission:agency.manage_members');
                Route::delete('/agency/team/{member}/invitation', [AgencyTeamController::class, 'cancelInvitation'])
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
                Route::middleware(['permission:billing.manage', 'throttle:billing'])->group(function (): void {
                    Route::get('/billing', [BillingController::class, 'show']);
                    Route::post('/billing/checkout-sessions', [BillingController::class, 'checkout']);
                    Route::post('/billing/portal-sessions', [BillingController::class, 'portal']);
                    Route::get('/billing/promotion-campaigns', [PromotionCampaignController::class, 'index']);
                    Route::post('/billing/promotion-campaigns', [PromotionCampaignController::class, 'store']);
                    Route::patch('/billing/promotion-campaigns/{campaign}', [PromotionCampaignController::class, 'update']);
                });
                Route::middleware(['permission:integration.configure', 'throttle:integrations'])
                    ->prefix('integrations')->group(function (): void {
                        Route::get('/connections', [IntegrationController::class, 'index']);
                        Route::post('/connections', [IntegrationController::class, 'store']);
                        Route::get('/connections/{connection}', [IntegrationController::class, 'show']);
                        Route::patch('/connections/{connection}', [IntegrationController::class, 'update']);
                        Route::delete('/connections/{connection}', [IntegrationController::class, 'destroy']);
                        Route::get('/connections/{connection}/mappings', [IntegrationController::class, 'mappings']);
                        Route::get('/connections/{connection}/metadata', [IntegrationController::class, 'metadata']);
                        Route::post('/connections/{connection}/mappings', [IntegrationController::class, 'storeMapping']);
                        Route::get('/connections/{connection}/syncs', [IntegrationController::class, 'syncs']);
                        Route::post('/connections/{connection}/syncs', [IntegrationController::class, 'startSync']);
                        Route::get('/syncs/{sync}', [IntegrationController::class, 'showSync']);
                        Route::get('/import-errors', [IntegrationController::class, 'importErrors']);
                        Route::get('/duplicate-candidates', [DuplicateCandidateController::class, 'index']);
                        Route::patch('/duplicate-candidates/{candidate}', [DuplicateCandidateController::class, 'update']);
                    });
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
                Route::post('/listings/{listing}/ai-suggestions', [AiListingController::class, 'store'])
                    ->middleware(['permission:listing.update', 'throttle:ai']);
                Route::post('/listings/{listing}/ai-suggestions/{suggestion}/apply', [AiListingController::class, 'apply'])
                    ->middleware(['permission:listing.update', 'throttle:ai']);
            });
        });
    });
});
