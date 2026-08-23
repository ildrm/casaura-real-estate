<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\ApiException;
use App\Domain\Search\PublicListingPresenter;
use App\Http\Controllers\Controller;
use App\Models\SearchDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AdvancedMarketplaceController extends Controller
{
    public function __construct(private readonly PublicListingPresenter $presenter) {}

    public function recommendations(string $listing): JsonResponse
    {
        $subject = SearchDocument::query()->where('status', 'published')->findOrFail($listing);
        $candidates = SearchDocument::query()->where('status', 'published')
            ->where('listing_id', '!=', $subject->listing_id)->limit(100)->get()
            ->map(function (SearchDocument $candidate) use ($subject): array {
                $score = 0;
                $reasons = [];
                if ($candidate->property_type_slug === $subject->property_type_slug) {
                    $score += 40;
                    $reasons[] = 'same_property_type';
                }
                if ($candidate->locality && $candidate->locality === $subject->locality) {
                    $score += 30;
                    $reasons[] = 'same_locality';
                }
                if ($candidate->bedrooms !== null && $candidate->bedrooms === $subject->bedrooms) {
                    $score += 20;
                    $reasons[] = 'same_bedroom_count';
                }
                if ($candidate->price_currency === $subject->price_currency && $candidate->price_amount_minor && $subject->price_amount_minor) {
                    $difference = abs($candidate->price_amount_minor - $subject->price_amount_minor) / max(1, $subject->price_amount_minor);
                    if ($difference <= 0.2) {
                        $score += 10;
                        $reasons[] = 'similar_price';
                    }
                }

                return ['document' => $candidate, 'score' => $score, 'reasons' => $reasons];
            })->filter(fn (array $item) => $item['score'] > 0)
            ->sortByDesc(fn (array $item) => sprintf('%03d-%s', $item['score'], $item['document']->listing_id))
            ->take(12)->values();

        return response()->json(['data' => $candidates->map(fn (array $item) => [
            'listing' => $this->presenter->card($item['document']),
            'score' => $item['score'],
            'reasons' => $item['reasons'],
        ])]);
    }

    public function mapLayers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'layer' => ['required', 'in:density,price_band,property_type'],
            'bounds' => ['nullable', 'string', 'max:120'],
        ]);
        $query = SearchDocument::query()->where('status', 'published')
            ->whereNotNull('public_latitude')->whereNotNull('public_longitude');
        if ($validated['layer'] === 'price_band') {
            $query->where('price_currency', 'USD');
        }
        if (! empty($validated['bounds'])) {
            $parts = array_map('floatval', explode(',', $validated['bounds']));
            if (count($parts) !== 4 || $parts[0] >= $parts[2] || $parts[1] >= $parts[3]) {
                throw new ApiException('MAP_BOUNDS_INVALID', 'Map bounds are invalid.', 422);
            }
            [$minLng, $minLat, $maxLng, $maxLat] = $parts;
            $query->whereBetween('public_longitude', [$minLng, $maxLng])
                ->whereBetween('public_latitude', [$minLat, $maxLat]);
        }
        $documents = $query->limit(5000)->get();
        $buckets = $documents->groupBy(fn (SearchDocument $document) => number_format(round($document->public_latitude, 2), 2).','.
            number_format(round($document->public_longitude, 2), 2))
            ->filter(fn ($group) => $group->count() >= 5)
            ->map(function ($group, string $key) use ($validated): array {
                [$latitude, $longitude] = array_map('floatval', explode(',', $key));
                $prices = $group->pluck('price_amount_minor')->filter()->sort()->values();
                $value = match ($validated['layer']) {
                    'density' => $group->count(),
                    'price_band' => $this->median($prices->all()),
                    'property_type' => $group->groupBy('property_type_slug')
                        ->sortByDesc(fn ($items) => $items->count())->keys()->first(),
                };

                return ['latitude' => $latitude, 'longitude' => $longitude, 'count' => $group->count(), 'value' => $value];
            })->values();

        return response()->json(['data' => [
            'layer' => $validated['layer'],
            'buckets' => $buckets,
            'coordinate_policy' => 'public_approximate',
        ]]);
    }

    public function marketAnalytics(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'locality' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = Carbon::parse($validated['from'])->startOfDay();
        $to = Carbon::parse($validated['to'])->endOfDay();
        if ($from->diffInDays($to) > 366) {
            throw new ApiException('MARKET_RANGE_INVALID', 'Market reports support at most 366 days.', 422);
        }
        $query = SearchDocument::query()->where('status', 'published')->whereBetween('listed_at', [$from, $to]);
        foreach (['locality', 'region'] as $field) {
            if (! empty($validated[$field])) {
                $query->where($field, $validated[$field]);
            }
        }
        $documents = $query->limit(10000)->get();
        $sufficient = $documents->count() >= 5;
        $prices = $documents->pluck('price_amount_minor')->filter()->sort()->values()->all();
        $unitPrices = $documents->filter(fn (SearchDocument $item) => $item->price_amount_minor && $item->interior_area_sqm)
            ->map(fn (SearchDocument $item) => (int) round($item->price_amount_minor / $item->interior_area_sqm))
            ->sort()->values()->all();
        $ages = $documents->filter(fn (SearchDocument $item) => $item->listed_at)
            ->map(fn (SearchDocument $item) => $item->listed_at->diffInDays(now()))->sort()->values()->all();
        $consistentCurrency = $documents->pluck('price_currency')->filter()->unique()->values();
        $currency = $consistentCurrency->count() === 1 ? $consistentCurrency->first() : null;

        return response()->json(['data' => [
            'scope' => ['locality' => $validated['locality'] ?? null, 'region' => $validated['region'] ?? null],
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'cohort_size' => $documents->count(),
            'minimum_cohort' => 5,
            'sufficient_cohort' => $sufficient,
            'active_inventory' => $sufficient ? $documents->count() : null,
            'median_price_minor' => $sufficient && $currency === 'USD' ? $this->median($prices) : null,
            'median_unit_price_minor_per_sqm' => $sufficient && $currency === 'USD' ? $this->median($unitPrices) : null,
            'median_listing_age_days' => $sufficient ? $this->median($ages) : null,
            'currency' => $sufficient && $currency === 'USD' ? $currency : null,
            'generated_at' => now()->toISOString(),
        ]]);
    }

    /** @param list<int|float> $values */
    private function median(array $values): int|float|null
    {
        $count = count($values);
        if ($count === 0) {
            return null;
        }
        sort($values, SORT_NUMERIC);
        $middle = intdiv($count, 2);

        return $count % 2 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
