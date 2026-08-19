<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = DB::table('notifications')->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')->limit(50)->get()->map(fn (object $item) => $this->projection($item));

        return response()->json(['data' => $items]);
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        $request->validate(['read' => ['required', 'boolean']]);
        $item = DB::table('notifications')->where('user_id', $request->user()->id)->where('id', $notification)->firstOrFail();
        DB::table('notifications')->where('id', $item->id)->update(['read_at' => $request->boolean('read') ? now() : null]);

        return response()->json(['data' => $this->projection(DB::table('notifications')->where('id', $item->id)->first())]);
    }

    /** @return array<string, mixed> */
    private function projection(object $item): array
    {
        return [
            'id' => $item->id, 'type' => $item->type, 'title' => $item->title, 'body' => $item->body,
            'data' => $item->data ? json_decode($item->data, true) : null, 'read' => $item->read_at !== null, 'created_at' => $item->created_at,
        ];
    }
}
