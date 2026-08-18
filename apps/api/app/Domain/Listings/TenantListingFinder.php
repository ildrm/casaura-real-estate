<?php

namespace App\Domain\Listings;

use App\Domain\Tenancy\TenantContext;
use App\Models\Listing;

final class TenantListingFinder
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function find(string $id): Listing
    {
        return Listing::query()
            ->where('agency_id', $this->tenant->id())
            ->with([
                'property.propertyType',
                'property.address',
                'property.featureValues.definition',
                'property.amenities',
                'media.derivatives',
            ])
            ->findOrFail($id);
    }
}
