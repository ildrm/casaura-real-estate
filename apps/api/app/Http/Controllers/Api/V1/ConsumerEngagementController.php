<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Search\PublicListingPresenter;
use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\PropertyReaction;
use App\Models\SearchDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConsumerEngagementController extends Controller
{
    public function __construct(private readonly PublicListingPresenter $presenter) {}

    public function index(Request $request): JsonResponse
    {
        $favorites = Favorite::query()->where('user_id', $request->user()->id)->pluck('listing_id');
        $likes = PropertyReaction::query()->where('user_id', $request->user()->id)->where('reaction', 'like')->pluck('listing_id');
        $dislikes = PropertyReaction::query()->where('user_id', $request->user()->id)->where('reaction', 'dislike')->pluck('listing_id');

        return response()->json(['data' => [
            'favorites' => $this->cards($favorites->all()),
            'likes' => $this->cards($likes->all()),
            'dislikes' => $this->cards($dislikes->all()),
        ]]);
    }

    public function favorite(Request $request, string $listing): JsonResponse
    {
        $this->publicListing($listing);
        Favorite::query()->firstOrCreate(['user_id' => $request->user()->id, 'listing_id' => $listing]);

        return response()->json(['data' => ['listing_id' => $listing, 'favorite' => true]]);
    }

    public function unfavorite(Request $request, string $listing): JsonResponse
    {
        $this->publicListing($listing);
        Favorite::query()->where('user_id', $request->user()->id)->where('listing_id', $listing)->delete();

        return response()->json(['data' => ['listing_id' => $listing, 'favorite' => false]]);
    }

    public function react(Request $request, string $listing): JsonResponse
    {
        $this->publicListing($listing);
        $validated = $request->validate(['reaction' => ['required', Rule::in(['like', 'dislike'])]]);
        PropertyReaction::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'listing_id' => $listing],
            ['reaction' => $validated['reaction']],
        );

        return response()->json(['data' => ['listing_id' => $listing, 'reaction' => $validated['reaction']]]);
    }

    public function unreact(Request $request, string $listing): JsonResponse
    {
        $this->publicListing($listing);
        PropertyReaction::query()->where('user_id', $request->user()->id)->where('listing_id', $listing)->delete();

        return response()->json(['data' => ['listing_id' => $listing, 'reaction' => null]]);
    }

    private function publicListing(string $listing): SearchDocument
    {
        return SearchDocument::query()->where('status', 'published')->findOrFail($listing);
    }

    /** @param list<string> $listingIds @return list<array<string, mixed>> */
    private function cards(array $listingIds): array
    {
        if (! $listingIds) {
            return [];
        }

        return SearchDocument::query()->where('status', 'published')->whereIn('listing_id', $listingIds)
            ->get()->map(fn (SearchDocument $document) => $this->presenter->card($document))->all();
    }
}
