<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Ai\GroundedAiService;
use App\Domain\Tenancy\FeatureResolver;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiController extends Controller
{
    public function __construct(private readonly FeatureResolver $features, private readonly GroundedAiService $ai) {}

    public function search(Request $request): JsonResponse
    {
        $this->features->ensureEnabled('ai_search');
        $validated = $request->validate(['message' => ['required', 'string', 'min:3', 'max:2000']]);

        return response()->json(['data' => $this->ai->search($validated['message'], $request->user('sanctum'))]);
    }

    public function comparison(Request $request): JsonResponse
    {
        $this->features->ensureEnabled('ai_search');
        $validated = $request->validate([
            'message' => ['required', 'string', 'min:3', 'max:2000'],
            'listing_ids' => ['required', 'array', 'min:2', 'max:5'],
            'listing_ids.*' => ['required', 'uuid'],
        ]);

        return response()->json(['data' => $this->ai->comparison(
            $validated['message'],
            $validated['listing_ids'],
            $request->user('sanctum'),
        )]);
    }
}
