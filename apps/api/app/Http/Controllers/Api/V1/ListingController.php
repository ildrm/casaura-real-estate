<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Listings\ListingManager;
use App\Domain\Listings\TenantListingFinder;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ListingController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly TenantListingFinder $finder,
        private readonly ListingManager $manager,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['draft', 'changes_requested', 'in_review', 'published', 'withdrawn', 'needs_attention'])],
            'q' => ['nullable', 'string', 'max:120'],
            'property_type' => ['nullable', 'string', 'max:80'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'cursor' => ['nullable', 'string'],
        ]);
        $query = Listing::query()->where('agency_id', $this->tenant->id())->with([
            'property.propertyType', 'property.address', 'property.featureValues.definition',
            'property.amenities', 'media.derivatives',
        ]);
        if (isset($validated['status'])) {
            if ($validated['status'] === 'needs_attention') {
                $query->whereIn('status', ['draft', 'changes_requested'])->where('quality_score', '<', 80);
            } else {
                $query->where('status', $validated['status']);
            }
        }
        if (isset($validated['property_type'])) {
            $query->whereHas('property.propertyType', fn ($builder) => $builder->where('slug', $validated['property_type']));
        }
        if (isset($validated['q'])) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $validated['q']).'%';
            $query->where(function ($builder) use ($term): void {
                $builder->where('reference', 'like', $term)
                    ->orWhere('title', 'like', $term)
                    ->orWhereHas('property.address', fn ($address) => $address
                        ->where('normalized', 'like', $term));
            });
        }
        $paginator = $query->orderByDesc('updated_at')->orderByDesc('id')
            ->cursorPaginate($validated['limit'] ?? 20);

        return ListingResource::collection($paginator)->additional([
            'meta' => ['next_cursor' => $paginator->nextCursor()?->encode()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $listing = $this->manager->create($request, $this->validated($request, false));

        return (new ListingResource($listing))->response()->setStatusCode(201);
    }

    public function show(string $listing): ListingResource
    {
        return new ListingResource($this->finder->find($listing));
    }

    public function update(Request $request, string $listing): ListingResource
    {
        return new ListingResource($this->manager->update(
            $request,
            $this->finder->find($listing),
            $this->validated($request, true, $listing),
        ));
    }

    public function destroy(Request $request, string $listing): JsonResponse
    {
        $this->manager->delete($request, $this->finder->find($listing));

        return response()->json(status: 204);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $updating, ?string $listingId = null): array
    {
        $presence = $updating ? 'sometimes' : 'required';
        $referenceRule = Rule::unique('listings', 'reference')->where('agency_id', $this->tenant->id());
        if ($listingId) {
            $referenceRule->ignore($listingId);
        }

        return $request->validate([
            'version' => [$updating ? 'required' : 'nullable', 'integer', 'min:1'],
            'reference' => [$presence, 'string', 'min:2', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $referenceRule],
            'intent' => [$presence, Rule::in(['sale', 'rent'])],
            'property_type_slug' => [$presence, 'string', Rule::exists('property_types', 'slug')->where('is_active', true)],
            'title' => ['sometimes', 'nullable', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'price' => ['sometimes', 'nullable', 'array'],
            'price.amount_minor' => ['required_with:price', 'integer', 'min:0', 'max:99999999999999'],
            'price.currency' => ['required_with:price', 'string', 'size:3'],
            'bedrooms' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'bathrooms' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'interior_area' => ['sometimes', 'nullable', 'array'],
            'interior_area.value' => ['required_with:interior_area', 'numeric', 'min:0.1', 'max:10000000'],
            'interior_area.unit' => ['required_with:interior_area', Rule::in(['sq_ft', 'sqm'])],
            'address' => ['sometimes', 'nullable', 'array'],
            'address.line_1' => ['sometimes', 'nullable', 'string', 'max:180'],
            'address.line_2' => ['sometimes', 'nullable', 'string', 'max:180'],
            'address.locality' => ['sometimes', 'nullable', 'string', 'max:120'],
            'address.region' => ['sometimes', 'nullable', 'string', 'max:120'],
            'address.postal_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'address.country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'address.latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'address.longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'address.location_policy' => ['sometimes', Rule::in(['exact', 'approximate', 'hidden'])],
            'features' => ['sometimes', 'array', 'max:100'],
            'amenity_slugs' => ['sometimes', 'array', 'max:100'],
            'amenity_slugs.*' => ['string', 'distinct', Rule::exists('amenities', 'slug')->where('is_active', true)],
        ]);
    }
}
