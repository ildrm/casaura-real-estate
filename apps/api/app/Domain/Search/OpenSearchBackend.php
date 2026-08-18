<?php

namespace App\Domain\Search;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class OpenSearchBackend implements SearchBackend
{
    public function upsert(array $document): void
    {
        $document['public_point'] = $document['public_latitude'] !== null && $document['public_longitude'] !== null
            ? ['lat' => $document['public_latitude'], 'lon' => $document['public_longitude']]
            : null;
        $response = $this->request()
            ->withOptions(['query' => [
                'version' => $document['projection_version'],
                'version_type' => 'external_gte',
                'refresh' => 'wait_for',
            ]])
            ->put($this->index().'/_doc/'.$document['listing_id'], $document);
        if (! $response->successful()) {
            throw new SearchException('SEARCH_INDEX_UNAVAILABLE', 'The search projection could not be updated.');
        }
    }

    public function delete(string $listingId): void
    {
        $response = $this->request()->delete($this->index().'/_doc/'.$listingId.'?refresh=wait_for');
        if (! $response->successful() && $response->status() !== 404) {
            throw new SearchException('SEARCH_INDEX_UNAVAILABLE', 'The search projection could not be removed.');
        }
    }

    public function search(array $criteria): array
    {
        $must = [];
        $filter = [['term' => ['status' => 'published']]];
        if (isset($criteria['q'])) {
            $must[] = ['multi_match' => [
                'query' => $criteria['q'],
                'fields' => ['title^4', 'locality^3', 'region^2', 'description', 'search_text'],
                'fuzziness' => 'AUTO',
            ]];
        }
        foreach (['intent', 'price_currency', 'property_type_slug'] as $field) {
            if (isset($criteria[$field])) {
                $filter[] = ['term' => [$field => $criteria[$field]]];
            }
        }
        foreach ([
            'price_amount_minor' => ['min_price', 'max_price'],
            'bedrooms' => ['min_bedrooms', null],
            'bathrooms' => ['min_bathrooms', null],
            'interior_area_sqm' => ['min_area_sqm', 'max_area_sqm'],
        ] as $field => [$minimum, $maximum]) {
            $range = [];
            if ($minimum && isset($criteria[$minimum])) {
                $range['gte'] = $criteria[$minimum];
            }
            if ($maximum && isset($criteria[$maximum])) {
                $range['lte'] = $criteria[$maximum];
            }
            if ($range) {
                $filter[] = ['range' => [$field => $range]];
            }
        }
        foreach ($criteria['amenities'] ?? [] as $amenity) {
            $filter[] = ['term' => ['amenities' => $amenity]];
        }
        if (isset($criteria['verified_agency'])) {
            $filter[] = ['term' => ['agency_verified' => $criteria['verified_agency']]];
        }
        if (isset($criteria['bounds'])) {
            [$minLongitude, $minLatitude, $maxLongitude, $maxLatitude] = $criteria['bounds'];
            $filter[] = ['geo_bounding_box' => ['public_point' => [
                'top_left' => ['lat' => $maxLatitude, 'lon' => $minLongitude],
                'bottom_right' => ['lat' => $minLatitude, 'lon' => $maxLongitude],
            ]]];
        }
        if (isset($criteria['radius'])) {
            [$latitude, $longitude, $kilometres] = $criteria['radius'];
            $filter[] = ['geo_distance' => [
                'distance' => $kilometres.'km',
                'public_point' => ['lat' => $latitude, 'lon' => $longitude],
            ]];
        }

        $sort = match ($criteria['sort'] ?? 'newest') {
            'price_asc' => [['price_amount_minor' => ['order' => 'asc', 'missing' => '_last']], ['listing_id' => 'asc']],
            'price_desc' => [['price_amount_minor' => ['order' => 'desc', 'missing' => '_last']], ['listing_id' => 'desc']],
            default => [['listed_at' => ['order' => 'desc', 'missing' => '_last']], ['listing_id' => 'desc']],
        };
        $body = [
            'size' => $criteria['limit'] ?? 20,
            'track_total_hits' => true,
            'query' => ['bool' => ['must' => $must ?: [['match_all' => (object) []]], 'filter' => $filter]],
            'sort' => $sort,
        ];
        if ($cursor = $criteria['cursor'] ?? null) {
            $decoded = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true);
            if (! is_array($decoded) || ! isset($decoded['sort'])) {
                throw new SearchException('SEARCH_CURSOR_INVALID', 'The search cursor is invalid.', 422);
            }
            $body['search_after'] = $decoded['sort'];
        }

        $response = $this->request()->post($this->index().'/_search', $body);
        if (! $response->successful()) {
            throw new SearchException('SEARCH_UNAVAILABLE', 'Property search is temporarily unavailable.');
        }
        $hits = $response->json('hits.hits', []);
        $last = end($hits);

        return [
            'items' => array_values(array_map(fn (array $hit) => $hit['_source'], $hits)),
            'count' => (int) $response->json('hits.total.value', 0),
            'next_cursor' => count($hits) === ($criteria['limit'] ?? 20) && $last
                ? rtrim(strtr(base64_encode(json_encode(['sort' => $last['sort']], JSON_THROW_ON_ERROR)), '+/', '-_'), '=')
                : null,
        ];
    }

    public function reset(): void
    {
        $this->request()->delete($this->index());
        $response = $this->request()->put($this->index(), ['mappings' => ['properties' => [
            'listing_id' => ['type' => 'keyword'],
            'status' => ['type' => 'keyword'],
            'intent' => ['type' => 'keyword'],
            'price_currency' => ['type' => 'keyword'],
            'property_type_slug' => ['type' => 'keyword'],
            'amenities' => ['type' => 'keyword'],
            'agency_verified' => ['type' => 'boolean'],
            'public_point' => ['type' => 'geo_point'],
            'listed_at' => ['type' => 'date'],
            'title' => ['type' => 'text'],
            'description' => ['type' => 'text'],
            'search_text' => ['type' => 'text'],
        ]]]);
        if (! $response->successful()) {
            throw new SearchException('SEARCH_INDEX_UNAVAILABLE', 'The search index could not be initialized.');
        }
    }

    private function request(): PendingRequest
    {
        $request = Http::baseUrl(rtrim((string) config('search.opensearch.url'), '/'))
            ->acceptJson()->asJson()->timeout((int) config('search.opensearch.timeout', 3));
        if (config('search.opensearch.username')) {
            $request->withBasicAuth(
                (string) config('search.opensearch.username'),
                (string) config('search.opensearch.password'),
            );
        }

        return $request;
    }

    private function index(): string
    {
        return (string) config('search.index');
    }
}
