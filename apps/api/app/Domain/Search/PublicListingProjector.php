<?php

namespace App\Domain\Search;

use App\Jobs\ProcessSearchProjection;
use App\Models\Listing;
use App\Models\SearchDocument;
use App\Models\SearchProjectionOutbox;
use Illuminate\Support\Facades\DB;

final class PublicListingProjector
{
    public function __construct(private readonly SearchBackend $backend) {}

    public function enqueue(Listing $listing, string $operation): SearchProjectionOutbox
    {
        $outbox = SearchProjectionOutbox::query()->firstOrCreate([
            'listing_id' => $listing->id,
            'projection_version' => $listing->version,
            'operation' => $operation,
        ], [
            'available_at' => now(),
        ]);
        DB::afterCommit(fn () => ProcessSearchProjection::dispatch($outbox->id)->afterCommit());

        return $outbox;
    }

    public function process(SearchProjectionOutbox $outbox): void
    {
        if ($outbox->processed_at !== null) {
            return;
        }
        $listing = Listing::withTrashed()->with($this->relations())->find($outbox->listing_id);
        if ($outbox->operation === 'delete' || ! $listing || $listing->trashed() || $listing->status !== 'published') {
            if ($listing && $listing->status === 'published' && $listing->version > $outbox->projection_version) {
                $outbox->update(['processed_at' => now(), 'last_error' => null]);

                return;
            }
            SearchDocument::query()->whereKey($outbox->listing_id)->delete();
            $this->backend->delete($outbox->listing_id);
            $outbox->update(['processed_at' => now(), 'last_error' => null]);

            return;
        }

        $document = $this->document($listing);
        $currentVersion = SearchDocument::query()->whereKey($listing->id)->value('projection_version');
        if ($currentVersion === null || (int) $currentVersion <= $listing->version) {
            SearchDocument::query()->updateOrCreate(['listing_id' => $listing->id], $document);
            $this->backend->upsert($document);
        }
        $outbox->update(['processed_at' => now(), 'last_error' => null]);
    }

    public function projectNow(Listing $listing): void
    {
        $listing->loadMissing($this->relations());
        $document = $this->document($listing);
        SearchDocument::query()->updateOrCreate(['listing_id' => $listing->id], $document);
        $this->backend->upsert($document);
    }

    public function rebuild(): int
    {
        $this->backend->reset();
        SearchDocument::query()->delete();
        $count = 0;
        Listing::query()->where('status', 'published')->whereNull('deleted_at')
            ->with($this->relations())->orderBy('id')->chunk(100, function ($listings) use (&$count): void {
                foreach ($listings as $listing) {
                    $this->projectNow($listing);
                    $count++;
                }
            });

        return $count;
    }

    /** @return array<string, mixed> */
    private function document(Listing $listing): array
    {
        $address = $listing->property->address;
        $features = $listing->property->featureValues
            ->mapWithKeys(fn ($value) => [$value->definition->slug => $value->value])->all();
        $amenities = $listing->property->amenities->pluck('slug')->sort()->values()->all();
        $media = $listing->media->map(function ($item): array {
            $derivatives = $item->derivatives->keyBy('kind');

            return [
                'id' => $item->id,
                'alt_text' => $item->alt_text,
                'width' => $item->width,
                'height' => $item->height,
                'thumbnail_url' => $derivatives->has('thumbnail') ? "/api/v1/public/media/{$item->id}/thumbnail" : null,
                'display_url' => $derivatives->has('display') ? "/api/v1/public/media/{$item->id}/display" : null,
            ];
        })->all();
        $slug = $listing->slug ?: ((string) str($listing->title ?: 'property')->slug() ?: 'property');
        $searchText = collect([
            $listing->title, $listing->description, $listing->reference,
            $address?->locality, $address?->region, $address?->country_code,
            $listing->property->propertyType->name, $listing->agency->name,
            ...$amenities,
        ])->filter()->implode(' ');

        return [
            'listing_id' => $listing->id,
            'property_id' => $listing->property_id,
            'agency_id' => $listing->agency_id,
            'projection_version' => $listing->version,
            'slug' => $slug,
            'status' => 'published',
            'intent' => $listing->intent,
            'title' => $listing->title ?: 'Untitled property',
            'description' => $listing->description,
            'price_amount_minor' => $listing->price_amount_minor,
            'price_currency' => $listing->price_currency,
            'property_type_slug' => $listing->property->propertyType->slug,
            'property_type_name' => $listing->property->propertyType->name,
            'bedrooms' => $listing->property->bedrooms,
            'bathrooms' => $listing->property->bathrooms,
            'interior_area_sqm' => $listing->property->interior_area_sqm,
            'locality' => $address?->locality,
            'region' => $address?->region,
            'country_code' => $address?->country_code,
            'location_policy' => $address?->public_latitude !== null && $address?->public_longitude !== null
                ? ($address->public_location_policy ?? 'hidden')
                : 'hidden',
            'public_latitude' => $address?->public_latitude,
            'public_longitude' => $address?->public_longitude,
            'agency_name' => $listing->agency->name,
            'agency_slug' => $listing->agency->slug,
            'agency_verified' => $listing->agency->verification_status === 'verified',
            'listed_at' => $listing->published_at,
            'amenities' => $amenities,
            'features' => $features,
            'media' => $media,
            'search_text' => $searchText,
            'updated_at' => $listing->updated_at,
            'created_at' => $listing->created_at,
        ];
    }

    /** @return list<string> */
    private function relations(): array
    {
        return [
            'agency', 'property.propertyType', 'property.address',
            'property.featureValues.definition', 'property.amenities', 'media.derivatives',
        ];
    }
}
