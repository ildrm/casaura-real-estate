<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Search\PublicListingPresenter;
use App\Domain\Search\SearchBackend;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PublicSearchController extends Controller
{
    public function __construct(
        private readonly SearchBackend $search,
        private readonly PublicListingPresenter $presenter,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:160'],
            'intent' => ['nullable', Rule::in(['sale', 'rent'])],
            'min_price' => ['nullable', 'integer', 'min:0'],
            'max_price' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'property_type' => ['nullable', 'string', 'max:80'],
            'min_bedrooms' => ['nullable', 'integer', 'min:0', 'max:100'],
            'min_bathrooms' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'min_area_sqm' => ['nullable', 'numeric', 'min:0'],
            'max_area_sqm' => ['nullable', 'numeric', 'min:0'],
            'amenities' => ['nullable', 'string', 'max:500'],
            'verified_agency' => ['nullable', 'boolean'],
            'bounds' => ['nullable', 'string', 'max:160'],
            'radius' => ['nullable', 'string', 'max:160'],
            'sort' => ['nullable', Rule::in(['newest', 'price_asc', 'price_desc'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'cursor' => ['nullable', 'string', 'max:2000'],
        ]);
        if (isset($validated['min_price'], $validated['max_price']) && $validated['max_price'] < $validated['min_price']) {
            throw ValidationException::withMessages(['max_price' => ['The maximum price must be at least the minimum price.']]);
        }
        if (isset($validated['min_area_sqm'], $validated['max_area_sqm']) && $validated['max_area_sqm'] < $validated['min_area_sqm']) {
            throw ValidationException::withMessages(['max_area_sqm' => ['The maximum area must be at least the minimum area.']]);
        }
        $criteria = array_filter([
            'q' => isset($validated['q']) ? trim($validated['q']) : null,
            'intent' => $validated['intent'] ?? null,
            'min_price' => $validated['min_price'] ?? null,
            'max_price' => $validated['max_price'] ?? null,
            'price_currency' => isset($validated['currency']) ? strtoupper($validated['currency']) : null,
            'property_type_slug' => $validated['property_type'] ?? null,
            'min_bedrooms' => $validated['min_bedrooms'] ?? null,
            'min_bathrooms' => isset($validated['min_bathrooms']) ? (float) $validated['min_bathrooms'] : null,
            'min_area_sqm' => isset($validated['min_area_sqm']) ? (float) $validated['min_area_sqm'] : null,
            'max_area_sqm' => isset($validated['max_area_sqm']) ? (float) $validated['max_area_sqm'] : null,
            'amenities' => isset($validated['amenities']) ? array_values(array_filter(array_unique(explode(',', $validated['amenities'])))) : [],
            'verified_agency' => $validated['verified_agency'] ?? null,
            'bounds' => isset($validated['bounds']) ? $this->spatial($validated['bounds'], 4, 'bounds') : null,
            'radius' => isset($validated['radius']) ? $this->radius($validated['radius']) : null,
            'sort' => $validated['sort'] ?? 'newest',
            'limit' => $validated['limit'] ?? 20,
            'cursor' => $validated['cursor'] ?? null,
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);
        $result = $this->search->search($criteria);

        return response()->json([
            'data' => array_map(fn (array $document) => $this->presenter->card($document), $result['items']),
            'meta' => [
                'count' => $result['count'],
                'next_cursor' => $result['next_cursor'],
                'applied_filters' => array_filter($validated, fn ($value, $key) => ! in_array($key, ['cursor', 'limit'], true) && $value !== null && $value !== '', ARRAY_FILTER_USE_BOTH),
            ],
        ]);
    }

    /** @return list<float> */
    private function spatial(string $value, int $parts, string $field): array
    {
        $values = explode(',', $value);
        if (count($values) !== $parts || collect($values)->contains(fn ($item) => ! is_numeric(trim($item)))) {
            throw ValidationException::withMessages([$field => ['Use comma-separated numeric coordinates.']]);
        }
        $numbers = array_map(fn ($item) => (float) trim($item), $values);
        if ($field === 'bounds' && (
            $numbers[0] < -180 || $numbers[0] > 180 || $numbers[2] < -180 || $numbers[2] > 180
            || $numbers[1] < -90 || $numbers[1] > 90 || $numbers[3] < -90 || $numbers[3] > 90
            || $numbers[1] > $numbers[3]
        )) {
            throw ValidationException::withMessages([$field => [
                'Use valid minLongitude,minLatitude,maxLongitude,maxLatitude bounds; longitude may wrap the dateline.',
            ]]);
        }

        return $numbers;
    }

    /** @return list<float> */
    private function radius(string $value): array
    {
        $numbers = $this->spatial($value, 3, 'radius');
        if ($numbers[0] < -90 || $numbers[0] > 90 || $numbers[1] < -180 || $numbers[1] > 180
            || $numbers[2] <= 0 || $numbers[2] > (float) config('search.max_radius_km')) {
            throw ValidationException::withMessages(['radius' => ['Provide latitude,longitude,kilometres within the configured range.']]);
        }

        return $numbers;
    }
}
