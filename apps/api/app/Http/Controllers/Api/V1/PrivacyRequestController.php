<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\ApiException;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessPrivacyExport;
use App\Models\DataSubjectRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class PrivacyRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = DataSubjectRequest::query()
            ->where('subject_user_id', $request->user()->id)
            ->latest('created_at')
            ->limit(25)
            ->get()
            ->map(fn (DataSubjectRequest $item) => $this->projection($item));

        return response()->json(['data' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(['type' => ['required', 'string', 'in:export,deletion']]);
        $record = DB::transaction(function () use ($request, $validated): DataSubjectRequest {
            $existing = DataSubjectRequest::query()
                ->where('subject_user_id', $request->user()->id)
                ->where('type', $validated['type'])
                ->whereIn('status', ['pending', 'processing', 'pending_operator_review'])
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return $existing;
            }

            return DataSubjectRequest::query()->create([
                'subject_user_id' => $request->user()->id,
                'requested_by_user_id' => $request->user()->id,
                'type' => $validated['type'],
                'status' => $validated['type'] === 'export' ? 'pending' : 'pending_operator_review',
                'requested_at' => now(),
            ]);
        });

        if ($record->type === 'export' && $record->status === 'pending') {
            ProcessPrivacyExport::dispatch($record->id)->afterCommit();
        }

        return response()->json(['data' => $this->projection($record->fresh())], 202);
    }

    public function download(Request $request, DataSubjectRequest $privacyRequest): Response
    {
        if ($privacyRequest->subject_user_id !== $request->user()->id) {
            abort(404);
        }
        if ($privacyRequest->type !== 'export' || $privacyRequest->status !== 'completed') {
            throw new ApiException('PRIVACY_EXPORT_NOT_READY', 'The data export is not ready.', 409);
        }
        if (! $privacyRequest->expires_at || $privacyRequest->expires_at->isPast() || ! $privacyRequest->output_storage_key) {
            throw new ApiException('PRIVACY_EXPORT_EXPIRED', 'The data export has expired.', 410);
        }

        $encrypted = Storage::disk('privacy_exports')->get($privacyRequest->output_storage_key);
        if (hash('sha256', $encrypted) !== $privacyRequest->output_checksum_sha256) {
            throw new ApiException('PRIVACY_EXPORT_CORRUPT', 'The data export could not be verified.', 503);
        }
        $payload = Crypt::decryptString($encrypted);

        return response($payload, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="casaura-data-export.json"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** @return array<string, mixed> */
    private function projection(DataSubjectRequest $request): array
    {
        return [
            'id' => $request->id,
            'type' => $request->type,
            'status' => $request->status,
            'requested_at' => $request->requested_at,
            'completed_at' => $request->completed_at,
            'expires_at' => $request->expires_at,
            'download_available' => $request->type === 'export'
                && $request->status === 'completed'
                && $request->expires_at?->isFuture(),
        ];
    }
}
