<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminAuditController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['nullable', 'string', 'max:160'], 'agency_id' => ['nullable', 'uuid'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'], 'cursor' => ['nullable', 'string'],
        ]);
        $query = DB::table('audit_logs');
        foreach (['action', 'agency_id'] as $filter) {
            if (isset($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }
        $page = $query->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate($validated['limit'] ?? 50);
        $items = collect($page->items())->map(fn (object $log) => [
            'id' => $log->id, 'actor_user_id' => $log->actor_user_id, 'agency_id' => $log->agency_id,
            'action' => $log->action, 'entity_type' => $log->entity_type, 'entity_id' => $log->entity_id,
            'changed_fields' => array_values(array_unique(array_merge(
                array_keys((array) json_decode($log->before ?? '{}', true)), array_keys((array) json_decode($log->after ?? '{}', true)),
            ))),
            'request_id' => $log->request_id, 'created_at' => $log->created_at,
        ]);

        return response()->json(['data' => $items, 'meta' => ['next_cursor' => $page->nextCursor()?->encode()]]);
    }
}
