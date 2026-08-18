<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Listings\ListingManager;
use App\Domain\Listings\TenantListingFinder;
use App\Http\Controllers\Controller;
use App\Http\Resources\ListingResource;
use Illuminate\Http\Request;

class ListingWorkflowController extends Controller
{
    public function __construct(
        private readonly TenantListingFinder $finder,
        private readonly ListingManager $manager,
    ) {}

    public function submit(Request $request, string $listing): ListingResource
    {
        return new ListingResource($this->manager->submit($request, $this->finder->find($listing)));
    }

    public function publish(Request $request, string $listing): ListingResource
    {
        return new ListingResource($this->manager->publish($request, $this->finder->find($listing)));
    }

    public function requestChanges(Request $request, string $listing): ListingResource
    {
        $validated = $request->validate(['note' => ['required', 'string', 'min:3', 'max:2000']]);

        return new ListingResource($this->manager->requestChanges($request, $this->finder->find($listing), $validated['note']));
    }

    public function withdraw(Request $request, string $listing): ListingResource
    {
        return new ListingResource($this->manager->withdraw($request, $this->finder->find($listing)));
    }
}
