<?php

namespace App\Http\Resources;

use App\Domain\Listings\ListingQualityCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ListingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing([
            'property.propertyType',
            'property.address',
            'property.featureValues.definition',
            'property.amenities',
            'media.derivatives',
        ]);
        $quality = app(ListingQualityCalculator::class)->calculate($this->resource);
        $features = $this->property->featureValues
            ->mapWithKeys(fn ($value) => [$value->definition->slug => $value->value])
            ->all();
        $areaSqm = $this->property->interior_area_sqm !== null ? (float) $this->property->interior_area_sqm : null;

        return [
            'id' => $this->id,
            'property_id' => $this->property_id,
            'agency_id' => $this->agency_id,
            'reference' => $this->reference,
            'intent' => $this->intent,
            'status' => $this->status,
            'version' => $this->version,
            'title' => $this->title,
            'description' => $this->description,
            'price' => $this->price_amount_minor === null ? null : [
                'amount_minor' => $this->price_amount_minor,
                'currency' => $this->price_currency,
            ],
            'property' => [
                'property_type' => [
                    'slug' => $this->property->propertyType->slug,
                    'name' => $this->property->propertyType->name,
                ],
                'bedrooms' => $this->property->bedrooms,
                'bathrooms' => $this->property->bathrooms === null ? null : (float) $this->property->bathrooms,
                'interior_area' => $areaSqm === null ? null : [
                    'sqm' => $areaSqm,
                    'sq_ft' => round($areaSqm / 0.092903),
                ],
                'address' => $this->property->address?->only([
                    'line_1', 'line_2', 'locality', 'region', 'postal_code', 'country_code',
                ]),
                'features' => $features,
                'amenities' => $this->property->amenities->pluck('slug')->sort()->values()->all(),
            ],
            'quality' => $quality,
            'primary_media' => $this->media->isEmpty() ? null : new MediaResource($this->media->first()),
            'media_count' => $this->media->count(),
            'submitted_at' => $this->submitted_at,
            'published_at' => $this->published_at,
            'updated_at' => $this->updated_at,
        ];
    }

    public function withResponse(Request $request, $response): void
    {
        $response->headers->set('ETag', '"'.$this->version.'"');
    }
}
