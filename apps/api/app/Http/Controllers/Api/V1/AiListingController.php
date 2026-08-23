<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Ai\GroundedAiService;
use App\Domain\ApiException;
use App\Domain\Listings\ListingManager;
use App\Domain\Listings\TenantListingFinder;
use App\Domain\Tenancy\FeatureResolver;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\AiListingSuggestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AiListingController extends Controller
{
    public function __construct(
        private readonly TenantListingFinder $finder,
        private readonly TenantContext $tenant,
        private readonly FeatureResolver $features,
        private readonly GroundedAiService $ai,
        private readonly ListingManager $listings,
    ) {}

    public function store(Request $request, string $listing): JsonResponse
    {
        $record = $this->finder->find($listing);
        $this->features->ensureEnabled('ai_listing_writer', $this->tenant->agency());
        $validated = $request->validate([
            'instruction' => ['required', 'string', 'min:3', 'max:1000'],
            'version' => ['required', 'integer', 'min:1'],
        ]);
        if ($record->version !== (int) $validated['version']) {
            throw new ApiException('LISTING_VERSION_CONFLICT', 'The listing changed.', 409);
        }
        $record->loadMissing('property.propertyType', 'property.address', 'property.amenities', 'property.featureValues.definition');
        $facts = [
            'title' => $record->title,
            'description' => $record->description,
            'property_type' => $record->property->propertyType->name,
            'bedrooms' => $record->property->bedrooms,
            'bathrooms' => $record->property->bathrooms,
            'interior_area_sqm' => $record->property->interior_area_sqm,
            'locality' => $record->property->address?->locality,
            'region' => $record->property->address?->region,
            'amenities' => $record->property->amenities->pluck('slug')->all(),
            'features' => $record->property->featureValues->mapWithKeys(
                fn ($value) => [$value->definition->slug => $value->value],
            )->all(),
        ];
        $generation = $this->ai->listing(
            $validated['instruction'],
            $facts,
            $this->tenant->id(),
            $record->id,
            $request->user(),
        );
        $result = $generation['result'];
        $suggestion = AiListingSuggestion::query()->create([
            'agency_id' => $this->tenant->id(),
            'listing_id' => $record->id,
            'ai_generation_id' => $generation['generation']->id,
            'source_listing_version' => $record->version,
            'suggested_fields' => [
                'title' => mb_substr((string) ($result['title'] ?? $record->title), 0, 160),
                'description' => mb_substr((string) ($result['description'] ?? $result['text']), 0, 5000),
            ],
        ]);

        return response()->json(['data' => $this->data($suggestion)], 201);
    }

    public function apply(Request $request, string $listing, string $suggestion): JsonResponse
    {
        $record = $this->finder->find($listing);
        $validated = $request->validate([
            'fields' => ['required', 'array', 'min:1', 'max:2'],
            'fields.*' => ['required', 'in:title,description', 'distinct'],
            'version' => ['required', 'integer', 'min:1'],
        ]);
        $suggestionRecord = AiListingSuggestion::query()
            ->where('agency_id', $this->tenant->id())
            ->where('listing_id', $record->id)
            ->findOrFail($suggestion);
        if ($suggestionRecord->applied_at !== null) {
            throw new ApiException('AI_SUGGESTION_ALREADY_APPLIED', 'The suggestion was already applied.', 409);
        }
        if ($record->version !== (int) $validated['version'] ||
            $suggestionRecord->source_listing_version !== (int) $validated['version']) {
            throw new ApiException('LISTING_VERSION_CONFLICT', 'The listing changed after generation.', 409);
        }
        $updates = ['version' => $validated['version']];
        foreach ($validated['fields'] as $field) {
            $updates[$field] = $suggestionRecord->suggested_fields[$field];
        }
        DB::transaction(function () use ($request, $record, $updates, $validated, $suggestionRecord): void {
            $this->listings->update($request, $record, $updates);
            $suggestionRecord->update([
                'applied_fields' => $validated['fields'],
                'applied_by_user_id' => $request->user()->id,
                'applied_at' => now(),
            ]);
        });

        return response()->json(['data' => [...$this->data($suggestionRecord->refresh()), 'applied' => true]]);
    }

    /** @return array<string, mixed> */
    private function data(AiListingSuggestion $suggestion): array
    {
        return [
            'id' => $suggestion->id,
            'listing_id' => $suggestion->listing_id,
            'source_listing_version' => $suggestion->source_listing_version,
            'suggested_fields' => $suggestion->suggested_fields,
            'applied_fields' => $suggestion->applied_fields,
            'applied_at' => $suggestion->applied_at,
        ];
    }
}
