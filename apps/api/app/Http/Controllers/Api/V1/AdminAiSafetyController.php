<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminAiSafetyController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate(['category' => ['nullable', 'string', 'max:80']]);
        $query = DB::table('ai_safety_events')->select('id', 'category', 'action', 'rule_version', 'created_at');
        if (! empty($validated['category'])) {
            $query->where('category', $validated['category']);
        }

        return response()->json(['data' => $query->latest()->limit(100)->get()]);
    }
}
