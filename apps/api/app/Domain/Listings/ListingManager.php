<?php

namespace App\Domain\Listings;

use App\Domain\Search\PublicListingProjector;
use App\Domain\Search\PublicLocationPolicy;
use App\Domain\Tenancy\AuditRecorder;
use App\Domain\Tenancy\FeatureResolver;
use App\Domain\Tenancy\TenantContext;
use App\Models\Address;
use App\Models\Agency;
use App\Models\Amenity;
use App\Models\Listing;
use App\Models\ListingStatusHistory;
use App\Models\ListingVersion;
use App\Models\PriceHistory;
use App\Models\Property;
use App\Models\PropertyFeatureDefinition;
use App\Models\PropertyFeatureValue;
use App\Models\PropertyIdentifier;
use App\Models\PropertyType;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ListingManager
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ListingQualityCalculator $quality,
        private readonly ListingSnapshotter $snapshotter,
        private readonly AuditRecorder $audit,
        private readonly PublicLocationPolicy $publicLocation,
        private readonly PublicListingProjector $projector,
        private readonly FeatureResolver $features,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(Request $request, array $data): Listing
    {
        try {
            return DB::transaction(function () use ($request, $data): Listing {
                $agency = Agency::query()->whereKey($this->tenant->id())->lockForUpdate()->firstOrFail();
                $this->features->ensureEnabled('listing_creation', $agency);
                $quota = $this->features->quota('listing_creation', $agency);
                if ($quota !== null && Listing::query()->where('agency_id', $agency->id)->count() >= $quota) {
                    throw new ListingException('LISTING_QUOTA_EXCEEDED', 'The active plan listing quota has been reached.');
                }

                $type = PropertyType::query()->where('slug', $data['property_type_slug'])->where('is_active', true)->firstOrFail();
                $address = isset($data['address']) ? $this->createAddress($data['address']) : null;
                $property = Property::query()->create([
                    'agency_id' => $this->tenant->id(),
                    'property_type_id' => $type->id,
                    'address_id' => $address?->id,
                    'bedrooms' => $data['bedrooms'] ?? null,
                    'bathrooms' => $data['bathrooms'] ?? null,
                    'interior_area_sqm' => $this->areaInSquareMetres($data['interior_area'] ?? null),
                ]);
                PropertyIdentifier::query()->create([
                    'property_id' => $property->id,
                    'scheme' => 'agency_reference',
                    'value' => $data['reference'],
                    'source' => 'agency',
                ]);

                $listing = Listing::query()->create([
                    'agency_id' => $this->tenant->id(),
                    'property_id' => $property->id,
                    'created_by_user_id' => $request->user()->id,
                    'reference' => $data['reference'],
                    'slug' => Str::slug($data['title'] ?? 'property') ?: 'property',
                    'intent' => $data['intent'],
                    'status' => 'draft',
                    'title' => $data['title'] ?? null,
                    'description' => $data['description'] ?? null,
                    'price_amount_minor' => data_get($data, 'price.amount_minor'),
                    'price_currency' => strtoupper((string) data_get($data, 'price.currency', 'USD')),
                    'version' => 1,
                ]);

                if (array_key_exists('features', $data)) {
                    $this->syncFeatures($property, $data['features']);
                }
                if (array_key_exists('amenity_slugs', $data)) {
                    $this->syncAmenities($property, $data['amenity_slugs']);
                }

                $listing = $this->refreshQuality($listing);
                $this->appendVersion($listing, $request->user()->id);
                ListingStatusHistory::query()->create([
                    'listing_id' => $listing->id,
                    'from_status' => null,
                    'to_status' => 'draft',
                    'actor_user_id' => $request->user()->id,
                    'created_at' => now(),
                ]);
                if ($listing->price_amount_minor !== null) {
                    $this->appendPrice($listing, $request->user()->id);
                }
                $this->audit->record($request, 'listing.created', $listing, null, $this->snapshotter->snapshot($listing), $this->tenant->id());

                return $listing;
            });
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                throw new ListingException('LISTING_REFERENCE_EXISTS', 'That reference is already used by this agency.', 409);
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $data */
    public function update(Request $request, Listing $listing, array $data): Listing
    {
        return DB::transaction(function () use ($request, $listing, $data): Listing {
            $listing = $this->lock($listing->id);
            $this->ensureEditable($listing);
            if ((int) $data['version'] !== $listing->version) {
                throw new ListingException(
                    'LISTING_VERSION_CONFLICT',
                    'This listing changed in another session.',
                    409,
                    ['current_version' => $listing->version],
                );
            }

            $before = $this->snapshotter->snapshot($listing);
            $oldPrice = [$listing->price_amount_minor, $listing->price_currency];
            $listing->fill(Arr::only($data, ['reference', 'intent', 'title', 'description']));
            if (array_key_exists('title', $data)) {
                $listing->slug = Str::slug($data['title'] ?: 'property') ?: 'property';
            }
            if (array_key_exists('price', $data)) {
                $listing->price_amount_minor = data_get($data, 'price.amount_minor');
                $listing->price_currency = strtoupper((string) data_get($data, 'price.currency', 'USD'));
            }

            $property = $listing->property;
            if (isset($data['property_type_slug'])) {
                $property->property_type_id = PropertyType::query()
                    ->where('slug', $data['property_type_slug'])->where('is_active', true)->firstOrFail()->id;
            }
            foreach (['bedrooms', 'bathrooms'] as $field) {
                if (array_key_exists($field, $data)) {
                    $property->{$field} = $data[$field];
                }
            }
            if (array_key_exists('interior_area', $data)) {
                $property->interior_area_sqm = $this->areaInSquareMetres($data['interior_area']);
            }
            if (array_key_exists('address', $data)) {
                $this->updateAddress($property, $data['address']);
            }
            $property->save();

            if (array_key_exists('features', $data)) {
                $this->syncFeatures($property, $data['features']);
            }
            if (array_key_exists('amenity_slugs', $data)) {
                $this->syncAmenities($property, $data['amenity_slugs']);
            }

            $listing->version++;
            $listing->save();
            $listing = $this->refreshQuality($listing);
            if ($oldPrice !== [$listing->price_amount_minor, $listing->price_currency] && $listing->price_amount_minor !== null) {
                $this->appendPrice($listing, $request->user()->id);
            }
            $this->appendVersion($listing, $request->user()->id);
            $this->audit->record($request, 'listing.updated', $listing, $before, $this->snapshotter->snapshot($listing), $this->tenant->id());

            return $listing;
        });
    }

    /**
     * Apply a validated provider delta without bypassing tenant ownership,
     * history, audit, or public-search projection invariants.
     *
     * @param  array<string, mixed>  $data
     */
    public function syncFromProvider(Request $request, Listing $listing, array $data): Listing
    {
        return DB::transaction(function () use ($request, $listing, $data): Listing {
            $listing = $this->lock($listing->id);
            $before = $this->snapshotter->snapshot($listing);
            $oldPrice = [$listing->price_amount_minor, $listing->price_currency];
            $fromStatus = $listing->status;

            $listing->fill(Arr::only($data, ['reference', 'intent', 'title', 'description']));
            if (array_key_exists('title', $data)) {
                $listing->slug = Str::slug($data['title'] ?: 'property') ?: 'property';
            }
            if (array_key_exists('price', $data)) {
                $listing->price_amount_minor = data_get($data, 'price.amount_minor');
                $listing->price_currency = strtoupper((string) data_get($data, 'price.currency', 'USD'));
            }

            $property = $listing->property;
            if (isset($data['property_type_slug'])) {
                $property->property_type_id = PropertyType::query()
                    ->where('slug', $data['property_type_slug'])->where('is_active', true)->firstOrFail()->id;
            }
            foreach (['bedrooms', 'bathrooms'] as $field) {
                if (array_key_exists($field, $data)) {
                    $property->{$field} = $data[$field];
                }
            }
            if (array_key_exists('interior_area', $data)) {
                $property->interior_area_sqm = $this->areaInSquareMetres($data['interior_area']);
            }
            if (array_key_exists('address', $data)) {
                $this->updateAddress($property, $data['address']);
            }
            $property->save();

            PropertyIdentifier::query()->updateOrCreate([
                'property_id' => $property->id,
                'scheme' => 'agency_reference',
            ], [
                'value' => $data['reference'],
                'source' => 'provider',
            ]);

            $providerStatus = mb_strtolower((string) ($data['_provider_status'] ?? 'active'));
            $withdrawn = in_array($providerStatus, [
                'canceled', 'cancelled', 'closed', 'deleted', 'expired', 'withdrawn',
            ], true);
            if ($withdrawn && $listing->status === 'published') {
                $listing->status = 'withdrawn';
                $listing->withdrawn_at = now();
            }

            $listing->version++;
            $listing->save();
            $listing = $this->refreshQuality($listing);
            if ($oldPrice !== [$listing->price_amount_minor, $listing->price_currency] && $listing->price_amount_minor !== null) {
                $this->appendPrice($listing, $request->user()->id);
            }
            if ($fromStatus !== $listing->status) {
                ListingStatusHistory::query()->create([
                    'listing_id' => $listing->id,
                    'from_status' => $fromStatus,
                    'to_status' => $listing->status,
                    'actor_user_id' => $request->user()->id,
                    'note' => 'Provider lifecycle synchronization',
                    'created_at' => now(),
                ]);
            }
            $this->appendVersion($listing, $request->user()->id);
            $this->audit->record(
                $request,
                $fromStatus !== $listing->status ? 'listing.provider_withdrawn' : 'listing.provider_updated',
                $listing,
                $before,
                $this->snapshotter->snapshot($listing),
                $this->tenant->id(),
            );
            if ($listing->status === 'published') {
                $this->projector->enqueue($listing, 'upsert');
            } elseif ($fromStatus === 'published') {
                $this->projector->enqueue($listing, 'delete');
            }

            return $listing;
        });
    }

    public function submit(Request $request, Listing $listing): Listing
    {
        return $this->transition($request, $listing, ['draft', 'changes_requested'], 'in_review', 'listing.submitted');
    }

    public function publish(Request $request, Listing $listing): Listing
    {
        return $this->transition($request, $listing, ['in_review'], 'published', 'listing.published');
    }

    public function requestChanges(Request $request, Listing $listing, string $note): Listing
    {
        return $this->transition($request, $listing, ['in_review'], 'changes_requested', 'listing.changes_requested', $note);
    }

    public function withdraw(Request $request, Listing $listing): Listing
    {
        return $this->transition($request, $listing, ['published'], 'withdrawn', 'listing.withdrawn');
    }

    public function delete(Request $request, Listing $listing): void
    {
        DB::transaction(function () use ($request, $listing): void {
            $listing = $this->lock($listing->id);
            if ($listing->status === 'published') {
                throw new ListingException('LISTING_WITHDRAWAL_REQUIRED', 'Withdraw this listing before deleting it.', 409);
            }
            $before = $this->snapshotter->snapshot($listing);
            $listing->delete();
            $listing->property()->firstOrFail()->delete();
            $this->projector->enqueue($listing, 'delete');
            $this->audit->record($request, 'listing.deleted', $listing, $before, null, $this->tenant->id());
        });
    }

    public function touchForMedia(Request $request, Listing $listing, string $action): Listing
    {
        $listing = $this->lock($listing->id);
        $this->ensureEditable($listing);
        $listing->version++;
        $listing->save();
        $listing = $this->refreshQuality($listing);
        $this->appendVersion($listing, $request->user()->id);
        $this->audit->record($request, $action, $listing, null, $this->snapshotter->snapshot($listing), $this->tenant->id());

        return $listing;
    }

    /** @param list<string> $allowedFrom */
    private function transition(Request $request, Listing $listing, array $allowedFrom, string $to, string $action, ?string $note = null): Listing
    {
        return DB::transaction(function () use ($request, $listing, $allowedFrom, $to, $action, $note): Listing {
            $listing = $this->lock($listing->id);
            if (! in_array($listing->status, $allowedFrom, true)) {
                throw new ListingException('LISTING_STATUS_INVALID', 'This status transition is not allowed.', 409);
            }
            $quality = $this->quality->calculate($listing);
            if (in_array($to, ['in_review', 'published'], true) && ! $quality['ready_for_review']) {
                throw new ListingException('LISTING_NOT_READY', 'Complete the listing before review.', 422, [
                    'checklist' => $quality['checklist'],
                ]);
            }

            $from = $listing->status;
            $listing->status = $to;
            $listing->version++;
            $listing->quality_score = $quality['score'];
            if ($to === 'in_review') {
                $listing->submitted_at = now();
            }
            if (in_array($to, ['published', 'changes_requested'], true)) {
                $listing->reviewed_by_user_id = $request->user()->id;
            }
            if ($to === 'published') {
                $listing->published_at = now();
            }
            if ($to === 'withdrawn') {
                $listing->withdrawn_at = now();
            }
            $listing->save();

            ListingStatusHistory::query()->create([
                'listing_id' => $listing->id,
                'from_status' => $from,
                'to_status' => $to,
                'actor_user_id' => $request->user()->id,
                'note' => $note,
                'created_at' => now(),
            ]);
            $this->appendVersion($listing, $request->user()->id);
            $this->audit->record($request, $action, $listing, ['status' => $from], ['status' => $to, 'note' => $note], $this->tenant->id());
            if ($to === 'published') {
                $this->projector->enqueue($listing, 'upsert');
            } elseif ($to === 'withdrawn') {
                $this->projector->enqueue($listing, 'delete');
            }

            return $listing;
        });
    }

    private function refreshQuality(Listing $listing): Listing
    {
        $listing->unsetRelations();
        $result = $this->quality->calculate($listing);
        $listing->quality_score = $result['score'];
        $listing->save();

        return $listing->fresh();
    }

    private function appendVersion(Listing $listing, string $actorUserId): void
    {
        ListingVersion::query()->create([
            'listing_id' => $listing->id,
            'version' => $listing->version,
            'actor_user_id' => $actorUserId,
            'snapshot' => $this->snapshotter->snapshot($listing),
            'created_at' => now(),
        ]);
    }

    private function appendPrice(Listing $listing, string $actorUserId): void
    {
        PriceHistory::query()->create([
            'listing_id' => $listing->id,
            'amount_minor' => $listing->price_amount_minor,
            'currency' => $listing->price_currency,
            'actor_user_id' => $actorUserId,
            'effective_at' => now(),
        ]);
    }

    private function lock(string $listingId): Listing
    {
        return Listing::query()
            ->where('agency_id', $this->tenant->id())
            ->lockForUpdate()
            ->findOrFail($listingId);
    }

    private function ensureEditable(Listing $listing): void
    {
        if (! in_array($listing->status, ['draft', 'changes_requested'], true)) {
            throw new ListingException('LISTING_NOT_EDITABLE', 'Only drafts or requested changes can be edited.', 409);
        }
    }

    /** @param array<string, mixed> $data */
    private function createAddress(array $data): Address
    {
        return Address::query()->create($this->addressAttributes($data));
    }

    /** @param array<string, mixed> $data */
    private function updateAddress(Property $property, array $data): void
    {
        $address = $property->address;
        if ($address) {
            $address->update($this->addressAttributes($data));
        } else {
            $address = $this->createAddress($data);
            $property->address_id = $address->id;
        }
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function addressAttributes(array $data): array
    {
        $parts = Arr::only($data, ['line_1', 'line_2', 'locality', 'region', 'postal_code', 'country_code', 'latitude', 'longitude', 'location_policy']);
        $parts['agency_id'] = $this->tenant->id();
        $parts['country_code'] = isset($parts['country_code']) ? strtoupper((string) $parts['country_code']) : null;
        $parts['normalized'] = collect(Arr::only($parts, ['line_1', 'line_2', 'locality', 'region', 'postal_code', 'country_code']))
            ->filter()->implode(', ');
        $location = $this->publicLocation->derive(
            isset($parts['latitude']) ? (float) $parts['latitude'] : null,
            isset($parts['longitude']) ? (float) $parts['longitude'] : null,
            (string) ($parts['location_policy'] ?? 'approximate'),
            $this->tenant->id(),
        );
        unset($parts['location_policy']);
        $parts['public_location_policy'] = $location['policy'];
        $parts['public_latitude'] = $location['latitude'];
        $parts['public_longitude'] = $location['longitude'];

        return $parts;
    }

    /** @param array{value: int|float, unit: string}|null $area */
    private function areaInSquareMetres(?array $area): ?float
    {
        if ($area === null) {
            return null;
        }

        return round((float) $area['value'] * ($area['unit'] === 'sq_ft' ? 0.092903 : 1), 2);
    }

    /** @param array<string, mixed> $features */
    private function syncFeatures(Property $property, array $features): void
    {
        $definitions = PropertyFeatureDefinition::query()->where('is_active', true)
            ->whereIn('slug', array_keys($features))->get()->keyBy('slug');
        if ($definitions->count() !== count($features)) {
            throw new ListingException('FEATURE_VALUE_INVALID', 'One or more feature definitions are unknown.');
        }

        $property->featureValues()->delete();
        foreach ($features as $slug => $value) {
            $definition = $definitions->get($slug);
            $this->assertFeatureValue($definition, $value);
            PropertyFeatureValue::query()->create([
                'property_id' => $property->id,
                'property_feature_definition_id' => $definition->id,
                'value' => $value,
            ]);
        }
    }

    /** @param list<string> $slugs */
    private function syncAmenities(Property $property, array $slugs): void
    {
        $ids = Amenity::query()->where('is_active', true)->whereIn('slug', $slugs)->pluck('id');
        if ($ids->count() !== count(array_unique($slugs))) {
            throw new ListingException('AMENITY_INVALID', 'One or more amenities are unknown.');
        }
        $property->amenities()->sync($ids);
    }

    private function assertFeatureValue(PropertyFeatureDefinition $definition, mixed $value): void
    {
        $valid = match ($definition->value_type) {
            'boolean' => is_bool($value),
            'integer' => is_int($value),
            'decimal' => is_int($value) || is_float($value),
            'string' => is_string($value),
            'enum' => is_string($value) && in_array($value, $definition->validation['values'] ?? [], true),
            default => false,
        };
        $rules = $definition->validation ?? [];
        if ($valid && is_numeric($value)) {
            $valid = (! isset($rules['min']) || $value >= $rules['min'])
                && (! isset($rules['max']) || $value <= $rules['max']);
        }
        if (! $valid) {
            throw new ListingException('FEATURE_VALUE_INVALID', "The {$definition->slug} feature value is invalid.");
        }
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true);
    }
}
