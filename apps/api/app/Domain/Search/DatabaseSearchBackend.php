<?php

namespace App\Domain\Search;

use App\Models\SearchDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class DatabaseSearchBackend implements SearchBackend
{
    public function upsert(array $document): void
    {
        SearchDocument::query()->updateOrCreate(['listing_id' => $document['listing_id']], $document);
    }

    public function delete(string $listingId): void
    {
        SearchDocument::query()->whereKey($listingId)->delete();
    }

    public function search(array $criteria): array
    {
        $query = $this->filteredQuery($criteria);
        $spatialFallback = DB::getDriverName() !== 'pgsql'
            && (isset($criteria['bounds']) || isset($criteria['radius']));
        if ($spatialFallback) {
            return $this->fallbackSpatialSearch($query, $criteria);
        }

        $count = (clone $query)->count();
        $sort = $criteria['sort'] ?? 'newest';
        if ($cursor = $criteria['cursor'] ?? null) {
            $this->applyCursor($query, $this->decodeCursor($cursor, $sort), $sort);
        }
        $this->applyOrder($query, $sort);
        $limit = $criteria['limit'] ?? 20;
        $page = $query->limit($limit + 1)->get();
        $hasMore = $page->count() > $limit;
        $items = $page->take($limit)->values();

        return [
            'items' => $items->map(fn (SearchDocument $document) => $document->toArray())->all(),
            'count' => $count,
            'next_cursor' => $hasMore && $items->isNotEmpty()
                ? $this->encodeCursor($items->last(), $sort)
                : null,
        ];
    }

    public function reset(): void
    {
        SearchDocument::query()->delete();
    }

    /** @param array<string, mixed> $criteria */
    private function filteredQuery(array $criteria): Builder
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
        foreach ([
            'price_amount_minor' => ['min_price', 'max_price'],
            'bedrooms' => ['min_bedrooms', null],
            'bathrooms' => ['min_bathrooms', null],
            'interior_area_sqm' => ['min_area_sqm', 'max_area_sqm'],
        ] as $field => [$minimum, $maximum]) {
            if ($minimum && isset($criteria[$minimum])) {
                $query->where($field, '>=', $criteria[$minimum]);
            }
            if ($maximum && isset($criteria[$maximum])) {
                $query->where($field, '<=', $criteria[$maximum]);
            }
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

        return $query;
    }

    private function applyOrder(Builder $query, string $sort): void
    {
        match ($sort) {
            'price_asc' => $query->orderByRaw('CASE WHEN price_amount_minor IS NULL THEN 1 ELSE 0 END')
                ->orderBy('price_amount_minor')->orderBy('listing_id'),
            'price_desc' => $query->orderByRaw('CASE WHEN price_amount_minor IS NULL THEN 1 ELSE 0 END')
                ->orderByDesc('price_amount_minor')->orderByDesc('listing_id'),
            default => $query->orderByRaw('CASE WHEN listed_at IS NULL THEN 1 ELSE 0 END')
                ->orderByDesc('listed_at')->orderByDesc('listing_id'),
        };
    }

    /** @param array{value: int|string|null, id: string} $cursor */
    private function applyCursor(Builder $query, array $cursor, string $sort): void
    {
        $column = $sort === 'newest' ? 'listed_at' : 'price_amount_minor';
        $ascending = $sort === 'price_asc';
        $value = $cursor['value'];
        $id = $cursor['id'];
        $query->where(function (Builder $page) use ($column, $ascending, $value, $id): void {
            if ($value === null) {
                $page->whereNull($column)->where('listing_id', $ascending ? '>' : '<', $id);

                return;
            }
            $page->where(function (Builder $after) use ($column, $ascending, $value, $id): void {
                $after->where($column, $ascending ? '>' : '<', $value)
                    ->orWhere(function (Builder $tie) use ($column, $ascending, $value, $id): void {
                        $tie->where($column, $value)->where('listing_id', $ascending ? '>' : '<', $id);
                    });
            })->orWhereNull($column);
        });
    }

    /** @return array{value: int|string|null, id: string} */
    private function decodeCursor(string $cursor, string $sort): array
    {
        $decoded = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true);
        if (! is_array($decoded)
            || ($decoded['version'] ?? null) !== 1
            || ($decoded['sort'] ?? null) !== $sort
            || ! array_key_exists('value', $decoded)
            || ! is_string($decoded['id'] ?? null)
            || $decoded['id'] === '') {
            throw new SearchException('SEARCH_CURSOR_INVALID', 'The search cursor is invalid.', 422);
        }

        return ['value' => $decoded['value'], 'id' => $decoded['id']];
    }

    private function encodeCursor(SearchDocument $document, string $sort): string
    {
        $value = $sort === 'newest'
            ? $document->getRawOriginal('listed_at')
            : $document->price_amount_minor;

        return rtrim(strtr(base64_encode(json_encode([
            'version' => 1,
            'sort' => $sort,
            'value' => $value,
            'id' => $document->listing_id,
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /** @param array<string, mixed> $criteria */
    private function fallbackSpatialSearch(Builder $query, array $criteria): array
    {
        $documents = $query->get()->filter(fn (SearchDocument $document) => $this->matchesSpatial($document, $criteria));
        $documents = $this->sortCollection($documents, $criteria['sort'] ?? 'newest')->values();
        $count = $documents->count();
        $start = 0;
        if ($cursor = $criteria['cursor'] ?? null) {
            $decoded = $this->decodeCursor($cursor, $criteria['sort'] ?? 'newest');
            $index = $documents->search(fn (SearchDocument $document) => $document->listing_id === $decoded['id']);
            if ($index === false) {
                throw new SearchException('SEARCH_CURSOR_INVALID', 'The search cursor no longer identifies this result set.', 422);
            }
            $start = $index + 1;
        }
        $limit = $criteria['limit'] ?? 20;
        $page = $documents->slice($start, $limit + 1)->values();
        $hasMore = $page->count() > $limit;
        $items = $page->take($limit)->values();

        return [
            'items' => $items->map(fn (SearchDocument $document) => $document->toArray())->all(),
            'count' => $count,
            'next_cursor' => $hasMore && $items->isNotEmpty()
                ? $this->encodeCursor($items->last(), $criteria['sort'] ?? 'newest')
                : null,
        ];
    }

    /** @param array<string, mixed> $criteria */
    private function matchesSpatial(SearchDocument $document, array $criteria): bool
    {
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
    private function sortCollection(Collection $documents, string $sort): Collection
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

    private function distanceKm(float $latitudeA, float $longitudeA, float $latitudeB, float $longitudeB): float
    {
        $latitudeDelta = deg2rad($latitudeB - $latitudeA);
        $longitudeDelta = deg2rad($longitudeB - $longitudeA);
        $value = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($latitudeA)) * cos(deg2rad($latitudeB)) * sin($longitudeDelta / 2) ** 2;

        return 6371.0088 * 2 * atan2(sqrt($value), sqrt(1 - $value));
    }
}
