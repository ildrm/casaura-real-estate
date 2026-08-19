<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Analytics\AnalyticsRecorder;
use App\Domain\Search\PublicListingPresenter;
use App\Http\Controllers\Controller;
use App\Models\SearchDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicListingController extends Controller
{
    public function __construct(private readonly PublicListingPresenter $presenter) {}

    public function show(Request $request, string $listing, AnalyticsRecorder $analytics): JsonResponse
    {
        $document = SearchDocument::query()->where('status', 'published')->findOrFail($listing);
        $analytics->recordPublicView(
            $request,
            $document->agency_id,
            'listing.view',
            $document->listing_id,
            ['surface' => 'property_detail'],
        );

        return response()->json(['data' => $this->presenter->detail($document, $request->user('sanctum'))]);
    }
}
