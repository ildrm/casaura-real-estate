<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Listings\ListingException;
use App\Domain\Listings\TenantListingFinder;
use App\Domain\Media\ListingMediaManager;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\MediaResource;
use App\Models\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListingMediaController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly TenantListingFinder $finder,
        private readonly ListingMediaManager $manager,
    ) {}

    public function index(string $listing): AnonymousResourceCollection
    {
        $listing = $this->finder->find($listing);

        return MediaResource::collection($listing->media()->with('derivatives')->get());
    }

    public function store(Request $request, string $listing): JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || trim($key) === '' || mb_strlen($key) > 160) {
            throw new ListingException('IDEMPOTENCY_KEY_REQUIRED', 'Provide a valid Idempotency-Key header.');
        }
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:15360'],
            'alt_text' => ['nullable', 'string', 'max:300'],
        ]);
        $result = $this->manager->upload(
            $request,
            $this->finder->find($listing),
            $validated['file'],
            trim($key),
            $validated['alt_text'] ?? null,
        );

        return (new MediaResource($result['media']))->response()->setStatusCode($result['created'] ? 201 : 200);
    }

    public function reorder(Request $request, string $listing): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'media_ids' => ['required', 'array', 'min:1', 'max:30'],
            'media_ids.*' => ['required', 'uuid', 'distinct'],
        ]);

        return MediaResource::collection($this->manager->reorder($request, $this->finder->find($listing), $validated['media_ids']));
    }

    public function destroy(Request $request, string $listing, string $media): JsonResponse
    {
        $listingModel = $this->finder->find($listing);
        $mediaModel = Media::query()
            ->where('agency_id', $this->tenant->id())
            ->where('listing_id', $listingModel->id)
            ->with('derivatives')
            ->findOrFail($media);
        $this->manager->delete($request, $listingModel, $mediaModel);

        return response()->json(status: 204);
    }
}
