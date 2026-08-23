<?php

namespace App\Jobs;

use App\Domain\Privacy\PrivacyExportBuilder;
use App\Models\DataSubjectRequest;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ProcessPrivacyExport implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $requestId) {}

    public function uniqueId(): string
    {
        return $this->requestId;
    }

    public function handle(PrivacyExportBuilder $builder): void
    {
        $request = DB::transaction(function (): ?DataSubjectRequest {
            $record = DataSubjectRequest::query()->whereKey($this->requestId)->lockForUpdate()->first();
            if (! $record || $record->type !== 'export' || ! in_array($record->status, ['pending', 'failed'], true)) {
                return null;
            }
            $record->update(['status' => 'processing', 'started_at' => now(), 'failure_code' => null]);

            return $record;
        });
        if (! $request) {
            return;
        }

        try {
            $user = User::query()->findOrFail($request->subject_user_id);
            $payload = json_encode($builder->build($user), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $encrypted = Crypt::encryptString($payload);
            $key = "privacy-exports/{$request->subject_user_id}/{$request->id}.json.enc";
            Storage::disk('privacy_exports')->put($key, $encrypted);
            $request->update([
                'status' => 'completed',
                'output_storage_key' => $key,
                'output_checksum_sha256' => hash('sha256', $encrypted),
                'completed_at' => now(),
                'expires_at' => now()->addDays((int) config('privacy.export_retention_days')),
            ]);
        } catch (Throwable $exception) {
            $request->update(['status' => 'failed', 'failure_code' => 'EXPORT_PROCESSING_FAILED']);
            throw $exception;
        }
    }
}
