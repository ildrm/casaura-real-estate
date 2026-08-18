<?php

namespace App\Jobs;

use App\Domain\Search\PublicListingProjector;
use App\Models\SearchProjectionOutbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessSearchProjection implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly string $outboxId)
    {
        $this->onQueue('search');
    }

    public function handle(PublicListingProjector $projector): void
    {
        $outbox = SearchProjectionOutbox::query()->find($this->outboxId);
        if (! $outbox || $outbox->processed_at !== null) {
            return;
        }
        $outbox->increment('attempts');
        try {
            $projector->process($outbox->fresh());
        } catch (Throwable $exception) {
            $outbox->update([
                'last_error' => mb_substr($exception->getMessage(), 0, 2000),
                'available_at' => now()->addSeconds(min(300, 2 ** $outbox->attempts)),
            ]);
            if (config('queue.default') !== 'sync') {
                throw $exception;
            }
        }
    }
}
