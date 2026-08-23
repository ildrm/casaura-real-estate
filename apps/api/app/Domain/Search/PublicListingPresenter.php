<?php

namespace App\Domain\Search;

use App\Models\Favorite;
use App\Models\Listing;
use App\Models\PropertyReaction;
use App\Models\SearchDocument;
use App\Models\User;

final class PublicListingPresenter
{
    /** @param array<string, mixed>|SearchDocument $document @return array<string, mixed> */
    public function card(array|SearchDocument $document): array
    {
        $data = $document instanceof SearchDocument ? $document->toArray() : $document;
        $media = is_array($data['media'] ?? null) ? $data['media'] : [];
        $area = $data['interior_area_sqm'] ?? null;

        return [
            'id' => $data['listing_id'],
            'slug' => $data['slug'],
            'url' => "/property/{$data['slug']}-{$data['listing_id']}",
            'title' => $data['title'],
            'intent' => $data['intent'],
            'price' => $data['price_amount_minor'] === null ? null : [
                'amount_minor' => (int) $data['price_amount_minor'],
                'currency' => $data['price_currency'],
            ],
            'property_type' => [
                'slug' => $data['property_type_slug'],
                'name' => $data['property_type_name'],
            ],
            'bedrooms' => $data['bedrooms'] === null ? null : (int) $data['bedrooms'],
            'bathrooms' => $data['bathrooms'] === null ? null : (float) $data['bathrooms'],
            'interior_area' => $area === null ? null : [
                'sqm' => (float) $area,
                'sq_ft' => round((float) $area / 0.092903),
            ],
            'location' => [
                'label' => collect([$data['locality'], $data['region']])->filter()->implode(', ') ?: 'Location withheld',
                'locality' => $data['locality'],
                'region' => $data['region'],
                'country_code' => $data['country_code'],
                'policy' => $data['location_policy'],
                'latitude' => $data['public_latitude'] === null ? null : (float) $data['public_latitude'],
                'longitude' => $data['public_longitude'] === null ? null : (float) $data['public_longitude'],
            ],
            'agency' => [
                'id' => $data['agency_id'],
                'name' => $data['agency_name'],
                'slug' => $data['agency_slug'],
                'verified' => (bool) $data['agency_verified'],
            ],
            'primary_media' => $media[0] ?? null,
            'media_count' => count($media),
            'listed_at' => $data['listed_at'],
        ];
    }

    /** @return array<string, mixed> */
    public function detail(SearchDocument $document, ?User $user): array
    {
        $listing = Listing::query()->where('status', 'published')->with([
            'agency', 'priceHistory', 'property.propertyType', 'property.featureValues.definition',
            'property.amenities', 'media.derivatives',
        ])->findOrFail($document->listing_id);
        $data = array_merge($this->card($document), [
            'canonical_url' => "/property/{$document->slug}-{$document->listing_id}",
            'reference' => $listing->reference,
            'description' => $document->description,
            'features' => $document->features,
            'amenities' => $document->amenities,
            'media' => $document->media,
            'price_history' => $listing->priceHistory->sortBy('effective_at')->values()->map(fn ($price) => [
                'amount_minor' => $price->amount_minor,
                'currency' => $price->currency,
                'effective_at' => $price->effective_at,
            ])->all(),
            'agency' => array_merge($this->card($document)['agency'], [
                'short_description' => $listing->agency->short_description,
                'contact_handoff_available' => true,
            ]),
            'engagement' => $this->engagement($listing->id, $user),
            'similar_listings' => SearchDocument::query()
                ->where('status', 'published')
                ->where('property_type_slug', $document->property_type_slug)
                ->where('listing_id', '!=', $document->listing_id)
                ->limit(4)->get()->map(fn (SearchDocument $similar) => $this->card($similar))->all(),
        ]);

        return $data;
    }

    /** @return array{favorite: bool, reaction: ?string} */
    private function engagement(string $listingId, ?User $user): array
    {
        if (! $user) {
            return ['favorite' => false, 'reaction' => null];
        }

        return [
            'favorite' => Favorite::query()->where('user_id', $user->id)->where('listing_id', $listingId)->exists(),
            'reaction' => PropertyReaction::query()->where('user_id', $user->id)->where('listing_id', $listingId)->value('reaction'),
        ];
    }
}
