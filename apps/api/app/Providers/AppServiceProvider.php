<?php

namespace App\Providers;

use App\Domain\Ai\AiProvider;
use App\Domain\Ai\DeterministicAiProvider;
use App\Domain\Ai\OpenAiResponsesProvider;
use App\Domain\Billing\BillingProvider;
use App\Domain\Billing\DeterministicBillingProvider;
use App\Domain\Billing\StripeBillingProvider;
use App\Domain\Calendar\CalendarExporter;
use App\Domain\Calendar\ICalendarExporter;
use App\Domain\Integrations\RealEstateDataProviderClient;
use App\Domain\Integrations\ResoWebApiClient;
use App\Domain\Media\ClamAvMediaMalwareScanner;
use App\Domain\Media\LaravelMediaStorage;
use App\Domain\Media\MediaMalwareScanner;
use App\Domain\Media\MediaStorage;
use App\Domain\Media\SignatureMediaMalwareScanner;
use App\Domain\Newsletters\LocalNewsletterDelivery;
use App\Domain\Newsletters\NewsletterDelivery;
use App\Domain\Notifications\DatabaseNotificationDispatcher;
use App\Domain\Notifications\NotificationDispatcher;
use App\Domain\Operations\ProductionEnvironmentGuard;
use App\Domain\Search\DatabaseSearchBackend;
use App\Domain\Search\OpenSearchBackend;
use App\Domain\Search\SearchBackend;
use App\Domain\Tenancy\TenantContext;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->bind(MediaStorage::class, LaravelMediaStorage::class);
        $this->app->bind(MediaMalwareScanner::class, fn ($app) => config('media.scanner') === 'clamav'
            ? $app->make(ClamAvMediaMalwareScanner::class)
            : $app->make(SignatureMediaMalwareScanner::class));
        $this->app->bind(NotificationDispatcher::class, DatabaseNotificationDispatcher::class);
        $this->app->bind(CalendarExporter::class, ICalendarExporter::class);
        $this->app->bind(NewsletterDelivery::class, LocalNewsletterDelivery::class);
        $this->app->bind(RealEstateDataProviderClient::class, ResoWebApiClient::class);
        $this->app->bind(AiProvider::class, fn ($app) => config('ai.driver') === 'openai'
            ? $app->make(OpenAiResponsesProvider::class)
            : $app->make(DeterministicAiProvider::class));
        $this->app->bind(BillingProvider::class, fn ($app) => config('billing.driver') === 'stripe'
            ? $app->make(StripeBillingProvider::class)
            : $app->make(DeterministicBillingProvider::class));
        $this->app->bind(SearchBackend::class, fn ($app) => config('search.driver') === 'opensearch'
            ? $app->make(OpenSearchBackend::class)
            : $app->make(DatabaseSearchBackend::class));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        app(ProductionEnvironmentGuard::class)->assertReady();

        Queue::looping(function (): void {
            try {
                Cache::put('ops:worker:heartbeat', now()->getTimestamp(), now()->addMinutes(10));
            } catch (\Throwable) {
                // Readiness exposes a stale worker heartbeat if the cache is unavailable.
            }
        });

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            return rtrim((string) config('identity.frontend_url'), '/')
                .'/reset-password?'.http_build_query(['token' => $token, 'email' => $user->email]);
        });
        VerifyEmail::createUrlUsing(function (User $user): string {
            $verificationUrl = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
                'id' => $user->getKey(),
                'hash' => sha1($user->getEmailForVerification()),
            ]);

            return rtrim((string) config('identity.frontend_url'), '/')
                .'/verify-email/confirm?'.http_build_query(['verification_url' => $verificationUrl]);
        });

        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(10)->by($request->ip()),
            Limit::perMinute(5)->by(mb_strtolower((string) $request->input('email'))),
        ]);

        RateLimiter::for('media', fn (Request $request) => [
            Limit::perMinute(30)->by(($request->user()?->id ?? 'guest').'|'.$request->ip()),
        ]);

        RateLimiter::for('search', fn (Request $request) => [
            Limit::perMinute(120)->by($request->ip()),
        ]);

        RateLimiter::for('engagement', fn (Request $request) => [
            Limit::perMinute(90)->by(($request->user()?->id ?? 'guest').'|'.$request->ip()),
        ]);

        RateLimiter::for('lead', fn (Request $request) => [
            Limit::perMinute(12)->by($request->ip()),
            Limit::perHour(6)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()),
        ]);

        RateLimiter::for('newsletter', fn (Request $request) => [
            Limit::perMinute(10)->by($request->ip()),
        ]);

        RateLimiter::for('report', fn (Request $request) => [
            Limit::perHour(10)->by(($request->user()?->id ?? 'guest').'|'.$request->ip()),
        ]);

        RateLimiter::for('integrations', fn (Request $request) => [
            Limit::perMinute(30)->by(($request->user()?->id ?? 'guest').'|'.$request->ip()),
        ]);

        RateLimiter::for('ai', fn (Request $request) => [
            Limit::perMinute(20)->by(($request->user()?->id ?? 'guest').'|'.$request->ip()),
        ]);

        RateLimiter::for('billing', fn (Request $request) => [
            Limit::perMinute(20)->by(($request->user()?->id ?? 'guest').'|'.$request->ip()),
        ]);

        RateLimiter::for('webhooks', fn (Request $request) => [
            Limit::perMinute(600)->by($request->ip()),
        ]);
    }
}
