<?php

use App\Domain\Media\MediaStorage;
use App\Models\Media;
use Illuminate\Console\Command;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Schedule::command('reminders:dispatch')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('media:purge-quarantine')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('media:reconcile')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('privacy:enforce-retention')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('sanctum:prune-expired --hours=24')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::call(fn () => Cache::put('ops:scheduler:heartbeat', now()->getTimestamp(), now()->addMinutes(10)))
    ->name('ops:scheduler-heartbeat')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Artisan::command('media:purge-quarantine {--days=}', function (MediaStorage $storage): int {
    $days = $this->option('days');
    $retentionDays = $days === null ? (int) config('media.quarantine_retention_days') : (int) $days;
    if ($retentionDays < 1) {
        $this->error('The quarantine retention period must be at least one day.');

        return Command::FAILURE;
    }

    $purged = 0;
    Media::onlyTrashed()
        ->with('derivatives')
        ->where('deleted_at', '<=', now()->subDays($retentionDays))
        ->orderBy('id')
        ->chunkById(100, function ($mediaItems) use ($storage, &$purged): void {
            foreach ($mediaItems as $media) {
                $keys = collect([$media->storage_key])
                    ->merge($media->derivatives->pluck('storage_key'))
                    ->filter(fn ($key) => is_string($key) && str_starts_with($key, 'quarantine/'))
                    ->values()
                    ->all();
                if ($keys !== []) {
                    $storage->delete($keys);
                }
                $media->forceDelete();
                $purged++;
            }
        }, 'id');

    $this->info("Purged {$purged} quarantined media record(s).");

    return Command::SUCCESS;
})->purpose('Permanently remove expired quarantined listing media');

Artisan::command('media:reconcile', function (MediaStorage $storage): int {
    $missing = 0;
    Media::withTrashed()
        ->with('derivatives')
        ->orderBy('id')
        ->chunkById(100, function ($mediaItems) use ($storage, &$missing): void {
            foreach ($mediaItems as $media) {
                $keys = collect([$media->storage_key])->merge($media->derivatives->pluck('storage_key'));
                foreach ($keys as $key) {
                    if (is_string($key) && ! $storage->exists($key)) {
                        $this->error("Missing media object: {$key}");
                        $missing++;
                    }
                }
            }
        }, 'id');

    if ($missing > 0) {
        $this->error("Media reconciliation found {$missing} missing object(s).");

        return Command::FAILURE;
    }

    $this->info('Media reconciliation passed.');

    return Command::SUCCESS;
})->purpose('Verify that every referenced listing media object exists');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
