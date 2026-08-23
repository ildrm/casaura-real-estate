<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenancy\FeatureResolver;
use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\SearchDocument;
use Illuminate\Http\JsonResponse;

class PublicDiscoveryController extends Controller
{
    public function __invoke(FeatureResolver $features): JsonResponse
    {
        $listings = SearchDocument::query()->where('status', 'published')
            ->oldest('listing_id')->limit(45000)->get(['listing_id', 'slug', 'updated_at'])
            ->map(fn (SearchDocument $listing): array => [
                'id' => $listing->listing_id,
                'slug' => $listing->slug,
                'updated_at' => $listing->updated_at?->toISOString(),
            ]);
        $agencies = Agency::query()->where('status', 'active')->oldest('id')->limit(5000)->get()
            ->filter(fn (Agency $agency): bool => $features->resolve('agency_storefronts', $agency)['enabled'])
            ->map(fn (Agency $agency): array => [
                'slug' => $agency->slug,
                'updated_at' => $agency->updated_at?->toISOString(),
            ])->values();

        return response()->json(['data' => ['listings' => $listings, 'agencies' => $agencies]]);
    }
}
