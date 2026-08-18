<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Search\PublicListingPresenter;
use App\Http\Controllers\Controller;
use App\Models\SearchDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicListingController extends Controller
{
    public function __construct(private readonly PublicListingPresenter $presenter) {}

    public function show(Request $request, string $listing): JsonResponse
    {
        $document = SearchDocument::query()->where('status', 'published')->findOrFail($listing);

        return response()->json(['data' => $this->presenter->detail($document, $request->user('sanctum'))]);
    }
}
