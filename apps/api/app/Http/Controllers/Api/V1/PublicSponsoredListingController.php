<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Search\PublicListingPresenter;
use App\Domain\Tenancy\FeatureResolver;
use App\Http\Controllers\Controller;
use App\Models\PromotionCampaign;
use App\Models\SearchDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PublicSponsoredListingController extends Controller
{
    public function __construct(
        private readonly FeatureResolver $features,
        private readonly PublicListingPresenter $presenter,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->features->ensureEnabled('sponsored_listings');
        $validated = $request->validate(['placement' => ['required', 'in:search,detail,storefront']]);
        $campaigns = PromotionCampaign::query()
            ->where('placement', $validated['placement'])
            ->where('status', 'active')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->whereColumn('impression_count', '<', 'impression_cap')
            ->with('policy')->orderBy('created_at')->limit(3)->get();
        $documents = SearchDocument::query()->where('status', 'published')
            ->whereIn('listing_id', $campaigns->pluck('listing_id'))->get()->keyBy('listing_id');
        $campaigns->reject(fn (PromotionCampaign $campaign) => $documents->has($campaign->listing_id))
            ->each(fn (PromotionCampaign $campaign) => $campaign->update([
                'status' => 'paused',
                'version' => $campaign->version + 1,
            ]));
        $data = $campaigns->filter(fn (PromotionCampaign $campaign) => $documents->has($campaign->listing_id))
            ->map(function (PromotionCampaign $campaign) use ($documents, $request): ?array {
                return DB::transaction(function () use ($campaign, $documents, $request): ?array {
                    $locked = PromotionCampaign::query()->whereKey($campaign->id)
                        ->where('status', 'active')->where('starts_at', '<=', now())->where('ends_at', '>', now())
                        ->whereColumn('impression_count', '<', 'impression_cap')->lockForUpdate()->first();
                    if (! $locked) {
                        return null;
                    }
                    $dedupe = hash_hmac(
                        'sha256',
                        $request->ip().'|'.now()->format('Y-m-d-H').'|'.$locked->id,
                        (string) config('app.key'),
                    );
                    $inserted = DB::table('promotion_impressions')->insertOrIgnore([
                        'id' => (string) str()->uuid(),
                        'promotion_campaign_id' => $locked->id,
                        'anonymous_dedupe_hash' => $dedupe,
                        'placement' => $locked->placement,
                        'occurred_at' => now(),
                    ]);
                    if (! $inserted) {
                        return null;
                    }
                    $locked->increment('impression_count');

                    return [
                        'sponsored' => true,
                        'label' => $campaign->policy->label,
                        'disclosure' => $campaign->policy->disclosure,
                        'placement' => $locked->placement,
                        'listing' => $this->presenter->card($documents->get($locked->listing_id)),
                    ];
                });
            })->filter()->values();

        return response()->json(['data' => $data]);
    }
}
