<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiGeneration;
use App\Models\AiSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AiSessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => AiSession::query()->where('user_id', $request->user()->id)
            ->latest('updated_at')->limit(50)->get(['id', 'purpose', 'status', 'created_at', 'updated_at'])]);
    }

    public function destroy(Request $request, string $session): JsonResponse
    {
        $record = AiSession::query()->where('user_id', $request->user()->id)->findOrFail($session);
        DB::transaction(function () use ($record): void {
            DB::table('ai_messages')->where('ai_session_id', $record->id)->delete();
            AiGeneration::query()->where('ai_session_id', $record->id)->update([
                'ai_session_id' => null,
                'output' => null,
                'parsed_filters' => null,
            ]);
            $record->delete();
        });

        return response()->json(null, 204);
    }

    public function feedback(Request $request, string $generation): JsonResponse
    {
        $validated = $request->validate(['helpful' => ['required', 'boolean']]);
        $record = AiGeneration::query()->whereHas('session', fn ($query) => $query
            ->where('user_id', $request->user()->id))->findOrFail($generation);
        $output = $record->output ?? [];
        $output['feedback'] = ['helpful' => $validated['helpful']];
        $record->update(['output' => $output]);

        return response()->json(['data' => ['generation_id' => $record->id, 'helpful' => $validated['helpful']]]);
    }
}
