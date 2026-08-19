<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\ApiException;
use App\Domain\Tenancy\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ReminderController extends Controller
{
    public function index(TenantContext $tenant): JsonResponse
    {
        $items = DB::table('reminders')->where('agency_id', $tenant->id())->orderBy('due_at')->limit(50)->get();

        return response()->json(['data' => $items->map(fn (object $item) => $this->projection($item))]);
    }

    public function store(Request $request, TenantContext $tenant, AuditRecorder $audit): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:200'],
            'due_at' => ['required', 'date'], 'lead_id' => ['nullable', 'uuid'], 'viewing_request_id' => ['nullable', 'uuid'],
            'assigned_user_id' => ['nullable', 'uuid'],
        ]);
        if (isset($validated['lead_id']) && ! DB::table('leads')->where('agency_id', $tenant->id())->where('id', $validated['lead_id'])->exists()) {
            abort(404);
        }
        if (isset($validated['viewing_request_id']) && ! DB::table('viewing_requests')->where('agency_id', $tenant->id())->where('id', $validated['viewing_request_id'])->exists()) {
            abort(404);
        }
        $assignedUserId = $validated['assigned_user_id'] ?? $request->user()->id;
        if (! DB::table('agency_members')->where('agency_id', $tenant->id())->where('user_id', $assignedUserId)->where('status', 'active')->exists()) {
            throw new ApiException('REMINDER_ASSIGNEE_INVALID', 'Select an active member of this agency.');
        }
        $id = (string) Str::uuid();
        DB::transaction(function () use ($request, $tenant, $audit, $validated, $assignedUserId, $id): void {
            DB::table('reminders')->insert([
                'id' => $id, 'agency_id' => $tenant->id(), 'assigned_user_id' => $assignedUserId,
                'lead_id' => $validated['lead_id'] ?? null, 'viewing_request_id' => $validated['viewing_request_id'] ?? null,
                'title' => $validated['title'], 'due_at' => Carbon::parse($validated['due_at'])->utc(), 'status' => 'pending',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $audit->recordEntity($request, 'reminder.created', 'reminder', $id, null, ['status' => 'pending'], $tenant->id());
        });

        return response()->json(['data' => $this->projection(DB::table('reminders')->where('id', $id)->first())], 201);
    }

    public function update(Request $request, string $reminder, TenantContext $tenant, AuditRecorder $audit): JsonResponse
    {
        $validated = $request->validate(['status' => ['required', Rule::in(['completed', 'cancelled'])]]);
        $current = DB::table('reminders')->where('agency_id', $tenant->id())->where('id', $reminder)->firstOrFail();
        DB::transaction(function () use ($request, $tenant, $audit, $validated, $current): void {
            DB::table('reminders')->where('id', $current->id)->update(['status' => $validated['status'], 'updated_at' => now()]);
            $audit->recordEntity($request, 'reminder.updated', 'reminder', $current->id, ['status' => $current->status], ['status' => $validated['status']], $tenant->id());
        });

        return response()->json(['data' => $this->projection(DB::table('reminders')->where('id', $reminder)->first())]);
    }

    /** @return array<string, mixed> */
    private function projection(object $item): array
    {
        return ['id' => $item->id, 'title' => $item->title, 'due_at' => $item->due_at, 'status' => $item->status, 'lead_id' => $item->lead_id, 'viewing_request_id' => $item->viewing_request_id];
    }
}
