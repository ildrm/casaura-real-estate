<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Analytics\AnalyticsRecorder;
use App\Domain\ApiException;
use App\Domain\Tenancy\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    public function index(Request $request, TenantContext $tenant): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['new', 'contacted', 'qualified', 'viewing', 'won', 'lost'])],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'cursor' => ['nullable', 'string'],
        ]);
        $query = DB::table('leads')->where('agency_id', $tenant->id());
        foreach (['status', 'priority'] as $filter) {
            if (isset($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }
        $page = $query->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate($validated['limit'] ?? 20);

        return response()->json([
            'data' => collect($page->items())->map(fn (object $lead) => $this->projection($lead))->all(),
            'meta' => ['next_cursor' => $page->nextCursor()?->encode()],
        ]);
    }

    public function show(string $lead, TenantContext $tenant): JsonResponse
    {
        return response()->json(['data' => $this->projection($this->find($lead, $tenant))]);
    }

    public function update(
        Request $request,
        string $lead,
        TenantContext $tenant,
        AuditRecorder $audit,
        AnalyticsRecorder $analytics,
    ): JsonResponse {
        $validated = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::in(['new', 'contacted', 'qualified', 'viewing', 'won', 'lost'])],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high'])],
            'assigned_member_id' => ['sometimes', 'nullable', 'uuid'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        $current = $this->find($lead, $tenant);
        if ((int) $current->version !== (int) $validated['version']) {
            throw new ApiException('LEAD_VERSION_CONFLICT', 'The lead changed since it was loaded.', 409, ['current_version' => (int) $current->version]);
        }
        if (array_key_exists('assigned_member_id', $validated) && $validated['assigned_member_id'] !== null) {
            $validAssignee = DB::table('agency_members')->where('id', $validated['assigned_member_id'])
                ->where('agency_id', $tenant->id())->where('status', 'active')->exists();
            if (! $validAssignee) {
                throw new ApiException('LEAD_ASSIGNEE_INVALID', 'Select an active member of this agency.');
            }
        }

        DB::transaction(function () use ($request, $lead, $tenant, $audit, $analytics, $validated, $current): void {
            $changes = array_intersect_key($validated, array_flip(['status', 'priority', 'assigned_member_id']));
            $statusChanged = isset($changes['status']) && $changes['status'] !== $current->status;
            $assignmentChanged = array_key_exists('assigned_member_id', $changes)
                && $changes['assigned_member_id'] !== $current->assigned_member_id;
            if ($statusChanged && $current->first_responded_at === null && in_array($changes['status'], ['contacted', 'qualified', 'viewing', 'won'], true)) {
                $changes['first_responded_at'] = now();
            }
            $changes['version'] = (int) $current->version + 1;
            $changes['last_activity_at'] = now();
            $changes['updated_at'] = now();
            $updated = DB::table('leads')->where('id', $lead)->where('agency_id', $tenant->id())
                ->where('version', $current->version)->update($changes);
            if ($updated !== 1) {
                throw new ApiException('LEAD_VERSION_CONFLICT', 'The lead changed since it was loaded.', 409);
            }
            if ($statusChanged || $assignmentChanged) {
                DB::table('lead_status_history')->insert([
                    'id' => (string) Str::uuid(), 'lead_id' => $lead, 'actor_user_id' => $request->user()->id,
                    'from_assigned_member_id' => $current->assigned_member_id,
                    'to_assigned_member_id' => $changes['assigned_member_id'] ?? $current->assigned_member_id,
                    'from_status' => $current->status, 'to_status' => $changes['status'] ?? $current->status,
                    'note' => $validated['note'] ?? null, 'created_at' => now(),
                ]);
            }
            if ($statusChanged) {
                $analytics->recordOutcome($tenant->id(), 'lead.status_changed', $current->listing_id, [
                    'from_status' => $current->status,
                    'to_status' => $changes['status'],
                ]);
            }
            $audit->recordEntity($request, 'lead.updated', 'lead', $lead, [
                'status' => $current->status, 'priority' => $current->priority, 'assigned_member_id' => $current->assigned_member_id,
            ], $changes, $tenant->id());
        });

        return response()->json(['data' => $this->projection($this->find($lead, $tenant))]);
    }

    private function find(string $id, TenantContext $tenant): object
    {
        return DB::table('leads')->where('agency_id', $tenant->id())->where('id', $id)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function projection(object $lead): array
    {
        return [
            'id' => $lead->id, 'listing_id' => $lead->listing_id, 'status' => $lead->status,
            'priority' => $lead->priority, 'contact' => ['name' => $lead->name, 'email' => $lead->email, 'phone' => $lead->phone],
            'assigned_member_id' => $lead->assigned_member_id, 'first_responded_at' => $lead->first_responded_at,
            'version' => (int) $lead->version, 'conversation_id' => DB::table('conversations')->where('lead_id', $lead->id)->value('id'),
            'created_at' => $lead->created_at,
        ];
    }
}
