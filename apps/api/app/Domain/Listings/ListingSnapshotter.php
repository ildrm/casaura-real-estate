<?php

namespace App\Domain\Listings;

use App\Models\Listing;

final class ListingSnapshotter
{
    /** @return array<string, mixed> */
    public function snapshot(Listing $listing): array
    {
        $listing->loadMissing([
            'property.propertyType',
            'property.address',
            'property.featureValues.definition',
            'property.amenities',
        ]);

        return [
            'listing' => [
                'id' => $listing->id,
                'reference' => $listing->reference,
                'intent' => $listing->intent,
                'status' => $listing->status,
                'title' => $listing->title,
                'description' => $listing->description,
                'price_amount_minor' => $listing->price_amount_minor,
                'price_currency' => $listing->price_currency,
                'version' => $listing->version,
                'quality_score' => $listing->quality_score,
            ],
            'property' => [
                'id' => $listing->property->id,
                'property_type_slug' => $listing->property->propertyType->slug,
                'bedrooms' => $listing->property->bedrooms,
                'bathrooms' => $listing->property->bathrooms,
                'interior_area_sqm' => $listing->property->interior_area_sqm,
                'address' => $listing->property->address?->only([
                    'line_1', 'line_2', 'locality', 'region', 'postal_code', 'country_code',
                ]),
                'features' => $listing->property->featureValues
                    ->mapWithKeys(fn ($value) => [$value->definition->slug => $value->value])
                    ->all(),
                'amenities' => $listing->property->amenities->pluck('slug')->sort()->values()->all(),
            ],
            'media_count' => $listing->media()->count(),
        ];
    }
}
