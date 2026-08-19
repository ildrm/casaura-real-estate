<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Administration\PlatformAuthorization;
use App\Domain\ApiException;
use App\Domain\Tenancy\AuditRecorder;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminModerationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['status' => ['nullable', Rule::in(['open', 'reviewing', 'resolved', 'dismissed'])], 'limit' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $query = DB::table('moderation_cases')
            ->join('abuse_reports', 'abuse_reports.id', '=', 'moderation_cases.abuse_report_id')
            ->select('moderation_cases.*', 'abuse_reports.details as report_details', 'abuse_reports.created_at as report_created_at');
        if (isset($validated['status'])) {
            $query->where('moderation_cases.status', $validated['status']);
        }
        $page = $query->orderByDesc('moderation_cases.created_at')->orderByDesc('moderation_cases.id')->cursorPaginate($validated['limit'] ?? 50);

        return response()->json(['data' => collect($page->items())->map(fn (object $case) => $this->projection($case)), 'meta' => ['next_cursor' => $page->nextCursor()?->encode()]]);
    }

    public function update(Request $request, string $case, AuditRecorder $audit, PlatformAuthorization $authorization): JsonResponse
    {
        $validated = $request->validate([
            'version' => ['required', 'integer', 'min:1'], 'status' => ['required', Rule::in(['open', 'reviewing', 'resolved', 'dismissed'])],
            'assigned_user_id' => ['sometimes', 'nullable', 'uuid', 'exists:users,id'], 'outcome' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);
        $current = DB::table('moderation_cases')->where('id', $case)->firstOrFail();
        if ((int) $current->version !== (int) $validated['version']) {
            throw new ApiException('MODERATION_VERSION_CONFLICT', 'The moderation case changed since it was loaded.', 409, ['current_version' => (int) $current->version]);
        }
        $allowed = ['open' => ['reviewing', 'dismissed'], 'reviewing' => ['resolved', 'dismissed'], 'resolved' => [], 'dismissed' => []];
        if (! in_array($validated['status'], $allowed[$current->status] ?? [], true)) {
            throw new ApiException('MODERATION_TRANSITION_INVALID', 'This moderation transition is not allowed.', 409);
        }
        if ($validated['status'] === 'resolved' && empty($validated['outcome'])) {
            throw new ApiException('MODERATION_OUTCOME_REQUIRED', 'A resolved case requires an outcome.');
        }
        if (isset($validated['assigned_user_id'])) {
            $assignee = User::query()->findOrFail($validated['assigned_user_id']);
            if (! $authorization->allows($assignee, 'comment.moderate')) {
                throw new ApiException('MODERATION_ASSIGNEE_INVALID', 'Select an active platform moderator.');
            }
        }
        DB::transaction(function () use ($request, $case, $audit, $validated, $current): void {
            $changes = [
                'status' => $validated['status'], 'assigned_user_id' => $validated['assigned_user_id'] ?? $current->assigned_user_id,
                'outcome' => $validated['outcome'] ?? null, 'note' => $validated['note'] ?? null,
                'version' => (int) $current->version + 1, 'updated_at' => now(),
            ];
            if ($validated['status'] === 'reviewing' && $changes['assigned_user_id'] === null) {
                $changes['assigned_user_id'] = $request->user()->id;
            }
            $updated = DB::table('moderation_cases')->where('id', $case)->where('version', $current->version)->update($changes);
            if ($updated !== 1) {
                throw new ApiException('MODERATION_VERSION_CONFLICT', 'The moderation case changed since it was loaded.', 409);
            }
            DB::table('moderation_case_history')->insert([
                'id' => (string) Str::uuid(), 'moderation_case_id' => $case, 'actor_user_id' => $request->user()->id,
                'assigned_user_id' => $changes['assigned_user_id'],
                'from_status' => $current->status, 'to_status' => $validated['status'],
                'outcome' => $validated['outcome'] ?? null, 'note' => $validated['note'] ?? null, 'created_at' => now(),
            ]);
            $audit->recordEntity($request, 'moderation.case_updated', 'moderation_case', $case, [
                'status' => $current->status, 'assigned_user_id' => $current->assigned_user_id,
            ], [
                'status' => $validated['status'], 'assigned_user_id' => $changes['assigned_user_id'],
                'outcome' => $validated['outcome'] ?? null,
            ]);
        });

        return response()->json(['data' => $this->projection(DB::table('moderation_cases')->where('id', $case)->first())]);
    }

    /** @return array<string, mixed> */
    private function projection(object $case): array
    {
        return [
            'id' => $case->id, 'status' => $case->status, 'category' => $case->category,
            'target_type' => $case->target_type, 'target_id' => $case->target_id,
            'assigned_user_id' => $case->assigned_user_id, 'outcome' => $case->outcome, 'note' => $case->note,
            'version' => (int) $case->version, 'created_at' => $case->created_at, 'updated_at' => $case->updated_at,
            'report' => [
                'details' => $case->report_details ?? DB::table('abuse_reports')->where('id', $case->abuse_report_id)->value('details'),
                'created_at' => $case->report_created_at ?? DB::table('abuse_reports')->where('id', $case->abuse_report_id)->value('created_at'),
            ],
        ];
    }
}
