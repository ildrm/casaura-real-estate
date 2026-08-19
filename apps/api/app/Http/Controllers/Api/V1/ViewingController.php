<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Analytics\AnalyticsRecorder;
use App\Domain\ApiException;
use App\Domain\Calendar\CalendarExporter;
use App\Domain\Notifications\NotificationDispatcher;
use App\Domain\Tenancy\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class ViewingController extends Controller
{
    public function index(Request $request, TenantContext $tenant): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'cursor' => ['nullable', 'string'],
        ]);
        $page = DB::table('viewing_requests')->where('agency_id', $tenant->id())
            ->orderBy('starts_at')->orderBy('id')->cursorPaginate($validated['limit'] ?? 20);
        $records = collect($page->items());
        $overlapCounts = $this->overlapCounts($records->pluck('id')->all());
        $items = $records->map(fn (object $viewing) => $this->projection($viewing, (int) ($overlapCounts[$viewing->id] ?? 0)));

        return response()->json(['data' => $items, 'meta' => ['next_cursor' => $page->nextCursor()?->encode()]]);
    }

    public function store(
        Request $request,
        TenantContext $tenant,
        NotificationDispatcher $notifications,
        AuditRecorder $audit,
        AnalyticsRecorder $analytics,
    ): JsonResponse {
        $validated = $request->validate([
            'lead_id' => ['required', 'uuid'],
            'starts_at' => ['required', 'date', 'after:now'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'timezone' => ['required', 'timezone:all'],
            'assigned_member_id' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);
        $lead = DB::table('leads')->where('agency_id', $tenant->id())->where('id', $validated['lead_id'])->firstOrFail();
        if (isset($validated['assigned_member_id']) && ! DB::table('agency_members')->where('agency_id', $tenant->id())
            ->where('id', $validated['assigned_member_id'])->where('status', 'active')->exists()) {
            throw new ApiException('VIEWING_ASSIGNEE_INVALID', 'Select an active member of this agency.');
        }
        $id = (string) Str::uuid();
        DB::transaction(function () use ($request, $tenant, $notifications, $audit, $analytics, $validated, $lead, $id): void {
            $now = now();
            DB::table('viewing_requests')->insert([
                'id' => $id, 'agency_id' => $tenant->id(), 'lead_id' => $lead->id, 'listing_id' => $lead->listing_id,
                'consumer_user_id' => $lead->consumer_user_id, 'assigned_member_id' => $validated['assigned_member_id'] ?? $lead->assigned_member_id,
                'starts_at' => $validated['starts_at'], 'ends_at' => $validated['ends_at'], 'timezone' => $validated['timezone'],
                'status' => 'requested', 'notes' => $validated['notes'] ?? null, 'version' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('viewing_status_history')->insert([
                'id' => (string) Str::uuid(), 'viewing_request_id' => $id, 'actor_user_id' => $request->user()->id,
                'from_status' => null, 'to_status' => 'requested', 'created_at' => $now,
            ]);
            if ($lead->consumer_user_id) {
                $notifications->dispatch($lead->consumer_user_id, $tenant->id(), 'viewing.requested', 'Viewing requested', null, [
                    'viewing_id' => $id,
                ], 'viewing-requested:'.$id);
            }
            $audit->recordEntity($request, 'viewing.created', 'viewing', $id, null, ['status' => 'requested'], $tenant->id());
            $analytics->recordOutcome($tenant->id(), 'viewing.requested', $lead->listing_id, ['status' => 'requested']);
        });

        return response()->json(['data' => $this->projection(DB::table('viewing_requests')->where('id', $id)->first())], 201);
    }

    public function update(
        Request $request,
        string $viewing,
        TenantContext $tenant,
        NotificationDispatcher $notifications,
        AuditRecorder $audit,
        AnalyticsRecorder $analytics,
    ): JsonResponse {
        $validated = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::in(['requested', 'confirmed', 'completed', 'cancelled', 'no_show'])],
            'starts_at' => ['sometimes', 'date', 'after:now'],
            'ends_at' => ['sometimes', 'date'],
            'timezone' => ['sometimes', 'timezone:all'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:3000'],
        ]);
        $current = DB::table('viewing_requests')->where('agency_id', $tenant->id())->where('id', $viewing)->firstOrFail();
        if ((int) $current->version !== (int) $validated['version']) {
            throw new ApiException('VIEWING_VERSION_CONFLICT', 'The viewing changed since it was loaded.', 409, ['current_version' => (int) $current->version]);
        }
        $startsAt = $validated['starts_at'] ?? $current->starts_at;
        $endsAt = $validated['ends_at'] ?? $current->ends_at;
        if (strtotime($endsAt) <= strtotime($startsAt)) {
            throw new ApiException('VIEWING_SCHEDULE_INVALID', 'The viewing end must be after its start.');
        }
        $next = $validated['status'] ?? $current->status;
        $allowed = [
            'requested' => ['confirmed', 'cancelled'], 'confirmed' => ['completed', 'cancelled', 'no_show'],
            'completed' => [], 'cancelled' => [], 'no_show' => [],
        ];
        if ($next !== $current->status && ! in_array($next, $allowed[$current->status] ?? [], true)) {
            throw new ApiException('VIEWING_TRANSITION_INVALID', 'This viewing status transition is not allowed.', 409);
        }

        DB::transaction(function () use ($request, $viewing, $tenant, $notifications, $audit, $analytics, $validated, $current, $startsAt, $endsAt, $next): void {
            $changes = array_intersect_key($validated, array_flip(['timezone', 'notes']));
            $changes['starts_at'] = $startsAt;
            $changes['ends_at'] = $endsAt;
            $changes['status'] = $next;
            $changes['version'] = (int) $current->version + 1;
            $changes['updated_at'] = now();
            $updated = DB::table('viewing_requests')->where('id', $viewing)->where('agency_id', $tenant->id())
                ->where('version', $current->version)->update($changes);
            if ($updated !== 1) {
                throw new ApiException('VIEWING_VERSION_CONFLICT', 'The viewing changed since it was loaded.', 409);
            }
            if ($next !== $current->status) {
                DB::table('viewing_status_history')->insert([
                    'id' => (string) Str::uuid(), 'viewing_request_id' => $viewing, 'actor_user_id' => $request->user()->id,
                    'from_status' => $current->status, 'to_status' => $next, 'note' => $validated['notes'] ?? null, 'created_at' => now(),
                ]);
                $analytics->recordOutcome($tenant->id(), 'viewing.'.$next, $current->listing_id, ['status' => $next]);
            }
            if ($next === 'confirmed') {
                $lead = DB::table('leads')->where('id', $current->lead_id)->first();
                if ($lead && $lead->status !== 'viewing') {
                    DB::table('leads')->where('id', $lead->id)->update(['status' => 'viewing', 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
                    DB::table('lead_status_history')->insert([
                        'id' => (string) Str::uuid(), 'lead_id' => $lead->id, 'actor_user_id' => $request->user()->id,
                        'from_assigned_member_id' => $lead->assigned_member_id,
                        'to_assigned_member_id' => $lead->assigned_member_id,
                        'from_status' => $lead->status, 'to_status' => 'viewing', 'created_at' => now(),
                    ]);
                    $analytics->recordOutcome($tenant->id(), 'lead.status_changed', $lead->listing_id, [
                        'from_status' => $lead->status, 'to_status' => 'viewing',
                    ]);
                    $audit->recordEntity($request, 'lead.status_changed_by_viewing', 'lead', $lead->id, [
                        'status' => $lead->status,
                    ], ['status' => 'viewing'], $tenant->id());
                }
            }
            if ($current->consumer_user_id && $next !== $current->status) {
                $notifications->dispatch($current->consumer_user_id, $tenant->id(), 'viewing.updated', 'Viewing updated', null, [
                    'viewing_id' => $viewing, 'status' => $next,
                ], 'viewing-updated:'.$viewing.':'.$next);
            }
            $audit->recordEntity($request, 'viewing.updated', 'viewing', $viewing, ['status' => $current->status], ['status' => $next], $tenant->id());
        });

        return response()->json(['data' => $this->projection(DB::table('viewing_requests')->where('id', $viewing)->first())]);
    }

    public function calendar(Request $request, string $viewing, CalendarExporter $calendar): Response
    {
        $record = DB::table('viewing_requests')->where('id', $viewing)->firstOrFail();
        $consumer = $record->consumer_user_id === $request->user()->id;
        $agency = DB::table('agency_members')->where('agency_id', $record->agency_id)->where('user_id', $request->user()->id)
            ->where('status', 'active')->exists();
        if (! $consumer && ! $agency) {
            abort(404);
        }
        if ($record->status !== 'confirmed') {
            throw new ApiException('VIEWING_NOT_EXPORTABLE', 'Only confirmed viewings can be exported.', 409);
        }

        return response($calendar->export((array) $record), 200, ['Content-Type' => 'text/calendar; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    private function projection(object $viewing, ?int $knownOverlapCount = null): array
    {
        $overlapCount = $knownOverlapCount ?? DB::table('viewing_requests')
            ->where('agency_id', $viewing->agency_id)
            ->where('id', '!=', $viewing->id)
            ->whereIn('status', ['requested', 'confirmed'])
            ->where('starts_at', '<', $viewing->ends_at)
            ->where('ends_at', '>', $viewing->starts_at)
            ->when(
                $viewing->assigned_member_id !== null,
                fn ($query) => $query->where('assigned_member_id', $viewing->assigned_member_id),
            )
            ->count();

        return [
            'id' => $viewing->id, 'lead_id' => $viewing->lead_id, 'listing_id' => $viewing->listing_id,
            'assigned_member_id' => $viewing->assigned_member_id,
            'starts_at' => $viewing->starts_at, 'ends_at' => $viewing->ends_at, 'timezone' => $viewing->timezone,
            'status' => $viewing->status, 'notes' => $viewing->notes, 'version' => (int) $viewing->version,
            'warnings' => $overlapCount > 0 ? [[
                'code' => 'VIEWING_SCHEDULE_OVERLAP',
                'message' => 'This viewing overlaps another active viewing.',
                'overlap_count' => $overlapCount,
            ]] : [],
        ];
    }

    /** @param list<string> $viewingIds @return array<string, int> */
    private function overlapCounts(array $viewingIds): array
    {
        if ($viewingIds === []) {
            return [];
        }

        return DB::table('viewing_requests as current')
            ->join('viewing_requests as other', function (JoinClause $join): void {
                $join->on('other.agency_id', '=', 'current.agency_id')
                    ->on('other.id', '!=', 'current.id')
                    ->on('other.starts_at', '<', 'current.ends_at')
                    ->on('other.ends_at', '>', 'current.starts_at');
            })
            ->whereIn('current.id', $viewingIds)
            ->whereIn('other.status', ['requested', 'confirmed'])
            ->where(fn ($query) => $query->whereNull('current.assigned_member_id')
                ->orWhereColumn('other.assigned_member_id', 'current.assigned_member_id'))
            ->groupBy('current.id')
            ->selectRaw('current.id, COUNT(other.id) as overlap_count')
            ->pluck('overlap_count', 'current.id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }
}
