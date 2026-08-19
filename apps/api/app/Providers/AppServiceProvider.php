<?php

namespace App\Providers;

use App\Domain\Calendar\CalendarExporter;
use App\Domain\Calendar\ICalendarExporter;
use App\Domain\Media\LaravelMediaStorage;
use App\Domain\Media\MediaStorage;
use App\Domain\Newsletters\LocalNewsletterDelivery;
use App\Domain\Newsletters\NewsletterDelivery;
use App\Domain\Notifications\DatabaseNotificationDispatcher;
use App\Domain\Notifications\NotificationDispatcher;
use App\Domain\Search\DatabaseSearchBackend;
use App\Domain\Search\OpenSearchBackend;
use App\Domain\Search\SearchBackend;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->bind(MediaStorage::class, LaravelMediaStorage::class);
        $this->app->bind(NotificationDispatcher::class, DatabaseNotificationDispatcher::class);
        $this->app->bind(CalendarExporter::class, ICalendarExporter::class);
        $this->app->bind(NewsletterDelivery::class, LocalNewsletterDelivery::class);
        $this->app->bind(SearchBackend::class, fn ($app) => config('search.driver') === 'opensearch'
            ? $app->make(OpenSearchBackend::class)
            : $app->make(DatabaseSearchBackend::class));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
    }
}
