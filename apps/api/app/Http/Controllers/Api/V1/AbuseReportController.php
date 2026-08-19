<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\ApiException;
use App\Domain\Tenancy\AuditRecorder;
use App\Http\Controllers\Controller;
use App\Models\SearchDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AbuseReportController extends Controller
{
    public function store(Request $request, string $listing, AuditRecorder $audit): JsonResponse
    {
        SearchDocument::query()->where('status', 'published')->findOrFail($listing);
        $validated = $request->validate([
            'category' => ['required', Rule::in(['misleading', 'fraud', 'duplicate', 'unavailable', 'inappropriate', 'other'])],
            'details' => ['nullable', 'string', 'max:5000'],
        ]);
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '' || mb_strlen($key) > 160) {
            throw new ApiException('IDEMPOTENCY_KEY_REQUIRED', 'A valid Idempotency-Key header is required.');
        }
        $hash = hash('sha256', json_encode([$listing, $validated], JSON_THROW_ON_ERROR));
        $existing = DB::table('abuse_reports')->where('reporter_user_id', $request->user()->id)->where('idempotency_key', $key)->first();
        if ($existing) {
            if (! hash_equals($existing->payload_hash, $hash)) {
                throw new ApiException('IDEMPOTENCY_CONFLICT', 'This idempotency key was already used for another report.', 409);
            }

            return response()->json(['data' => ['report_id' => $existing->id, 'case_id' => DB::table('moderation_cases')->where('abuse_report_id', $existing->id)->value('id')]]);
        }

        $ids = DB::transaction(function () use ($request, $listing, $validated, $key, $hash, $audit): array {
            $reportId = (string) Str::uuid();
            $caseId = (string) Str::uuid();
            DB::table('abuse_reports')->insert([
                'id' => $reportId, 'reporter_user_id' => $request->user()->id, 'listing_id' => $listing,
                'idempotency_key' => $key, 'payload_hash' => $hash, 'category' => $validated['category'],
                'details' => $validated['details'] ?? null, 'created_at' => now(),
            ]);
            DB::table('moderation_cases')->insert([
                'id' => $caseId, 'abuse_report_id' => $reportId, 'target_type' => 'listing', 'target_id' => $listing,
                'category' => $validated['category'], 'status' => 'open', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('moderation_case_history')->insert([
                'id' => (string) Str::uuid(), 'moderation_case_id' => $caseId, 'actor_user_id' => $request->user()->id,
                'from_status' => null, 'to_status' => 'open', 'created_at' => now(),
            ]);
            $audit->recordEntity($request, 'moderation.report_created', 'moderation_case', $caseId, null, [
                'category' => $validated['category'], 'status' => 'open',
            ]);

            return [$reportId, $caseId];
        });

        return response()->json(['data' => ['report_id' => $ids[0], 'case_id' => $ids[1]]], 201);
    }
}
