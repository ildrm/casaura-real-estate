<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\ApiException;
use App\Domain\Tenancy\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Listing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DuplicateCandidateController extends Controller
{
    public function __construct(private readonly TenantContext $tenant, private readonly AuditRecorder $audit) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => DB::table('duplicate_candidates')
            ->where('agency_id', $this->tenant->id())->latest()->limit(100)->get()
            ->map(fn ($candidate) => $this->data($candidate))->values()]);
    }

    public function update(Request $request, string $candidate): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['rejected', 'linked', 'merged', 'reverse'])],
            'version' => ['required', 'integer', 'min:1'],
        ]);

        return DB::transaction(function () use ($request, $candidate, $validated): JsonResponse {
            $record = DB::table('duplicate_candidates')->where('agency_id', $this->tenant->id())
                ->where('id', $candidate)->lockForUpdate()->first();
            abort_unless($record, 404);
            if ((int) $record->version !== (int) $validated['version']) {
                throw new ApiException('DUPLICATE_VERSION_CONFLICT', 'The duplicate candidate changed.', 409);
            }
            if ($validated['decision'] === 'reverse' && ! in_array($record->status, ['linked', 'merged'], true)) {
                throw new ApiException('DUPLICATE_REVERSAL_INVALID', 'This duplicate decision cannot be reversed.', 409);
            }
            $status = $validated['decision'] === 'reverse' ? 'pending' : $validated['decision'];
            $mergeSnapshot = $record->merge_snapshot;
            $source = $record->data_source_record_id
                ? DB::table('data_source_records')->where('id', $record->data_source_record_id)->lockForUpdate()->first()
                : null;
            if ($source && in_array($validated['decision'], ['linked', 'merged'], true)) {
                $target = Listing::query()
                    ->where('agency_id', $this->tenant->id())
                    ->where('property_id', $record->left_property_id)
                    ->latest()
                    ->first();
                if (! $target) {
                    throw new ApiException('DUPLICATE_TARGET_UNAVAILABLE', 'The canonical duplicate target is unavailable.', 409);
                }
                $mergeSnapshot = json_encode([
                    'source_property_id' => $source->property_id,
                    'source_listing_id' => $source->listing_id,
                    'source_outcome' => $source->outcome,
                    'left_property_id' => $record->left_property_id,
                    'right_property_id' => $record->right_property_id,
                ], JSON_THROW_ON_ERROR);
                DB::table('data_source_records')->where('id', $source->id)->update([
                    'property_id' => $target->property_id,
                    'listing_id' => $target->id,
                    'outcome' => $validated['decision'],
                    'updated_at' => now(),
                ]);
            } elseif ($source && $validated['decision'] === 'rejected') {
                DB::table('data_source_records')->where('id', $source->id)->update([
                    'outcome' => 'rejected',
                    'updated_at' => now(),
                ]);
            } elseif ($source && $validated['decision'] === 'reverse') {
                $snapshot = json_decode((string) $record->merge_snapshot, true);
                if (! is_array($snapshot)) {
                    throw new ApiException('DUPLICATE_REVERSAL_INVALID', 'The duplicate merge snapshot is unavailable.', 409);
                }
                DB::table('data_source_records')->where('id', $source->id)->update([
                    'property_id' => $snapshot['source_property_id'] ?? null,
                    'listing_id' => $snapshot['source_listing_id'] ?? null,
                    'outcome' => $snapshot['source_outcome'] ?? 'duplicate_review',
                    'updated_at' => now(),
                ]);
                $mergeSnapshot = null;
            }
            DB::table('duplicate_candidates')->where('id', $candidate)->update([
                'status' => $status,
                'decided_by_user_id' => $status === 'pending' ? null : $request->user()->id,
                'decided_at' => $status === 'pending' ? null : now(),
                'merge_snapshot' => $mergeSnapshot,
                'version' => $record->version + 1,
                'updated_at' => now(),
            ]);
            $action = $validated['decision'] === 'reverse'
                ? 'integration.duplicate_reversed'
                : 'integration.duplicate_reviewed';
            $this->audit->recordEntity($request, $action, 'duplicate_candidate', $candidate, [
                'status' => $record->status,
            ], ['status' => $status], $this->tenant->id());

            return response()->json(['data' => $this->data(DB::table('duplicate_candidates')->find($candidate))]);
        });
    }

    /** @return array<string, mixed> */
    private function data(object $candidate): array
    {
        return [
            'id' => $candidate->id,
            'left_property_id' => $candidate->left_property_id,
            'right_property_id' => $candidate->right_property_id,
            'data_source_record_id' => $candidate->data_source_record_id,
            'score' => (float) $candidate->score,
            'reasons' => json_decode((string) $candidate->reasons, true) ?: [],
            'status' => $candidate->status,
            'version' => (int) $candidate->version,
            'decided_at' => $candidate->decided_at,
            'created_at' => $candidate->created_at,
        ];
    }
}
