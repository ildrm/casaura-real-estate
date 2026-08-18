<?php

namespace App\Domain\Search;

use App\Models\SearchDocument;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class DatabaseSearchBackend implements SearchBackend
{
    public function upsert(array $document): void
    {
        SearchDocument::query()->updateOrCreate(
            ['listing_id' => $document['listing_id']],
            $document,
        );
    }

    public function delete(string $listingId): void
    {
        SearchDocument::query()->whereKey($listingId)->delete();
    }

    public function search(array $criteria): array
    {
        $query = SearchDocument::query()->where('status', 'published');
        if ($term = $criteria['q'] ?? null) {
            $term = str_replace(['%', '_'], '', mb_strtolower($term));
            $query->whereRaw('LOWER(search_text) LIKE ?', ["%{$term}%"]);
        }
        foreach (['intent', 'price_currency', 'property_type_slug'] as $field) {
            if (isset($criteria[$field])) {
                $query->where($field, $criteria[$field]);
            }
        }
        if (isset($criteria['min_price'])) {
            $query->where('price_amount_minor', '>=', $criteria['min_price']);
        }
        if (isset($criteria['max_price'])) {
            $query->where('price_amount_minor', '<=', $criteria['max_price']);
        }
        if (isset($criteria['min_bedrooms'])) {
            $query->where('bedrooms', '>=', $criteria['min_bedrooms']);
        }
        if (isset($criteria['min_bathrooms'])) {
            $query->where('bathrooms', '>=', $criteria['min_bathrooms']);
        }
        if (isset($criteria['min_area_sqm'])) {
            $query->where('interior_area_sqm', '>=', $criteria['min_area_sqm']);
        }
        if (isset($criteria['max_area_sqm'])) {
            $query->where('interior_area_sqm', '<=', $criteria['max_area_sqm']);
        }
        if (isset($criteria['verified_agency'])) {
            $query->where('agency_verified', $criteria['verified_agency']);
        }
        foreach ($criteria['amenities'] ?? [] as $amenity) {
            $query->whereJsonContains('amenities', $amenity);
        }
        if (DB::getDriverName() === 'pgsql') {
            if (isset($criteria['bounds'])) {
                [$minLongitude, $minLatitude, $maxLongitude, $maxLatitude] = $criteria['bounds'];
                if ($minLongitude <= $maxLongitude) {
                    $query->whereRaw('ST_Intersects(public_location, ST_MakeEnvelope(?, ?, ?, ?, 4326)::geography)', [
                        $minLongitude, $minLatitude, $maxLongitude, $maxLatitude,
                    ]);
                } else {
                    $query->where(function ($bounds) use ($minLongitude, $minLatitude, $maxLongitude, $maxLatitude): void {
                        $bounds->whereRaw('ST_Intersects(public_location, ST_MakeEnvelope(?, ?, 180, ?, 4326)::geography)', [
                            $minLongitude, $minLatitude, $maxLatitude,
                        ])->orWhereRaw('ST_Intersects(public_location, ST_MakeEnvelope(-180, ?, ?, ?, 4326)::geography)', [
                            $minLatitude, $maxLongitude, $maxLatitude,
                        ]);
                    });
                }
            }
            if (isset($criteria['radius'])) {
                [$latitude, $longitude, $kilometres] = $criteria['radius'];
                $query->whereRaw('ST_DWithin(public_location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)', [
                    $longitude, $latitude, $kilometres * 1000,
                ]);
            }
        }

        $documents = $query->get()
            ->filter(fn (SearchDocument $document) => $this->matchesSpatial($document, $criteria));
        $documents = $this->sort($documents, $criteria['sort'] ?? 'newest')->values();
        $count = $documents->count();
        $start = $this->cursorStart($documents, $criteria['cursor'] ?? null);
        $limit = $criteria['limit'] ?? 20;
        $page = $documents->slice($start, $limit + 1)->values();
        $hasMore = $page->count() > $limit;
        $items = $page->take($limit);

        return [
            'items' => $items->map(fn (SearchDocument $document) => $document->toArray())->all(),
            'count' => $count,
            'next_cursor' => $hasMore && $items->isNotEmpty()
                ? $this->encodeCursor($items->last()->listing_id)
                : null,
        ];
    }

    public function reset(): void
    {
        SearchDocument::query()->delete();
    }

    /** @param array<string, mixed> $criteria */
    private function matchesSpatial(SearchDocument $document, array $criteria): bool
    {
        if (! isset($criteria['bounds']) && ! isset($criteria['radius'])) {
            return true;
        }
        $latitude = $document->public_latitude;
        $longitude = $document->public_longitude;
        if ($latitude === null || $longitude === null) {
            return false;
        }
        if (isset($criteria['bounds'])) {
            [$minLongitude, $minLatitude, $maxLongitude, $maxLatitude] = $criteria['bounds'];
            $longitudeMatches = $minLongitude <= $maxLongitude
                ? $longitude >= $minLongitude && $longitude <= $maxLongitude
                : $longitude >= $minLongitude || $longitude <= $maxLongitude;
            if (! $longitudeMatches || $latitude < $minLatitude || $latitude > $maxLatitude) {
                return false;
            }
        }
        if (isset($criteria['radius'])) {
            [$targetLatitude, $targetLongitude, $kilometres] = $criteria['radius'];
            if ($this->distanceKm($latitude, $longitude, $targetLatitude, $targetLongitude) > $kilometres) {
                return false;
            }
        }

        return true;
    }

    /** @param Collection<int, SearchDocument> $documents @return Collection<int, SearchDocument> */
    private function sort(Collection $documents, string $sort): Collection
    {
        return match ($sort) {
            'price_asc' => $documents->sort(function (SearchDocument $left, SearchDocument $right): int {
                $price = ($left->price_amount_minor ?? PHP_INT_MAX) <=> ($right->price_amount_minor ?? PHP_INT_MAX);

                return $price !== 0 ? $price : strcmp($left->listing_id, $right->listing_id);
            }),
            'price_desc' => $documents->sort(function (SearchDocument $left, SearchDocument $right): int {
                $price = ($right->price_amount_minor ?? -1) <=> ($left->price_amount_minor ?? -1);

                return $price !== 0 ? $price : strcmp($right->listing_id, $left->listing_id);
            }),
            default => $documents->sort(function (SearchDocument $left, SearchDocument $right): int {
                $listed = ($right->listed_at?->getTimestamp() ?? 0) <=> ($left->listed_at?->getTimestamp() ?? 0);

                return $listed !== 0 ? $listed : strcmp($right->listing_id, $left->listing_id);
            }),
        };
    }

    /** @param Collection<int, SearchDocument> $documents */
    private function cursorStart(Collection $documents, ?string $cursor): int
    {
        if (! $cursor) {
            return 0;
        }
        $decoded = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true);
        if (! is_array($decoded) || ! isset($decoded['id'])) {
            throw new SearchException('SEARCH_CURSOR_INVALID', 'The search cursor is invalid.', 422);
        }
        $index = $documents->search(fn (SearchDocument $document) => $document->listing_id === $decoded['id']);

        return $index === false ? 0 : $index + 1;
    }

    private function encodeCursor(string $listingId): string
    {
        return rtrim(strtr(base64_encode(json_encode(['id' => $listingId], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    private function distanceKm(float $latitudeA, float $longitudeA, float $latitudeB, float $longitudeB): float
    {
        $latitudeDelta = deg2rad($latitudeB - $latitudeA);
        $longitudeDelta = deg2rad($longitudeB - $longitudeA);
        $value = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($latitudeA)) * cos(deg2rad($latitudeB)) * sin($longitudeDelta / 2) ** 2;

        return 6371.0088 * 2 * atan2(sqrt($value), sqrt(1 - $value));
    }
}
