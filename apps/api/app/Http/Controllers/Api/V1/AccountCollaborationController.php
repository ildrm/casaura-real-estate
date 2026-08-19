<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountCollaborationController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $viewings = DB::table('viewing_requests')->where('consumer_user_id', $request->user()->id)
            ->orderBy('starts_at')->limit(50)->get()->map(fn (object $item) => [
                'id' => $item->id, 'lead_id' => $item->lead_id, 'listing_id' => $item->listing_id,
                'starts_at' => $item->starts_at, 'ends_at' => $item->ends_at, 'timezone' => $item->timezone, 'status' => $item->status,
            ]);
        $conversations = DB::table('conversations')
            ->join('conversation_participants', 'conversation_participants.conversation_id', '=', 'conversations.id')
            ->where('conversation_participants.user_id', $request->user()->id)
            ->orderByDesc('conversations.last_message_at')->limit(50)
            ->get(['conversations.id', 'conversations.lead_id', 'conversations.listing_id', 'conversations.subject', 'conversations.last_message_at']);

        return response()->json(['data' => ['viewings' => $viewings, 'conversations' => $conversations]]);
    }
}
