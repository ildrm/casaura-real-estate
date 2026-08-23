<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\ApiException;
use App\Domain\Tenancy\AuditRecorder;
use App\Domain\Tenancy\FeatureResolver;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Models\PromotionCampaign;
use App\Models\PromotionPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PromotionCampaignController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly FeatureResolver $features,
        private readonly AuditRecorder $audit,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => PromotionCampaign::query()
            ->where('agency_id', $this->tenant->id())->latest()->limit(100)->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $agency = $this->tenant->agency()->load('subscription');
        $this->features->ensureEnabled('sponsored_listings', $agency);
        $validated = $request->validate([
            'listing_id' => ['required', 'uuid'],
            'policy_id' => ['required', 'uuid'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'impression_cap' => ['required', 'integer', 'min:1'],
        ]);
        $listing = Listing::query()->where('agency_id', $agency->id)->where('status', 'published')
            ->findOrFail($validated['listing_id']);
        $policy = PromotionPolicy::query()->where('status', 'active')
            ->where('starts_at', '<=', now())->where('ends_at', '>', now())->findOrFail($validated['policy_id']);
        if (! in_array($agency->subscription?->plan_id, $policy->eligible_plan_ids, true)) {
            throw new ApiException('PROMOTION_PLAN_INELIGIBLE', 'The active plan is not eligible for this placement.', 403);
        }
        $start = Carbon::parse($validated['starts_at']);
        $end = Carbon::parse($validated['ends_at']);
        if ($start->lt($policy->starts_at) || $end->gt($policy->ends_at) ||
            (int) $validated['impression_cap'] > $policy->max_impressions) {
            throw new ApiException('PROMOTION_BOUNDS_INVALID', 'The campaign exceeds the policy bounds.', 422);
        }
        $campaign = DB::transaction(function () use ($request, $agency, $listing, $policy, $start, $end, $validated): PromotionCampaign {
            $campaign = PromotionCampaign::query()->create([
                'agency_id' => $agency->id,
                'listing_id' => $listing->id,
                'promotion_policy_id' => $policy->id,
                'placement' => $policy->placement,
                'starts_at' => $start,
                'ends_at' => $end,
                'impression_cap' => $validated['impression_cap'],
                'status' => 'active',
            ]);
            $this->audit->record($request, 'promotion.campaign_created', $campaign, null, [
                'listing_id' => $listing->id,
                'policy_id' => $policy->id,
                'placement' => $policy->placement,
            ], $agency->id);

            return $campaign;
        });

        return response()->json(['data' => $campaign], 201);
    }

    public function update(Request $request, string $campaign): JsonResponse
    {
        $record = PromotionCampaign::query()->where('agency_id', $this->tenant->id())->findOrFail($campaign);
        $validated = $request->validate([
            'status' => ['required', Rule::in(['active', 'paused', 'ended'])],
            'version' => ['required', 'integer', 'min:1'],
        ]);
        if ($record->version !== (int) $validated['version']) {
            throw new ApiException('PROMOTION_CAMPAIGN_VERSION_CONFLICT', 'The campaign changed.', 409);
        }
        $record->update(['status' => $validated['status'], 'version' => $record->version + 1]);
        $this->audit->record($request, 'promotion.campaign_updated', $record, null, [
            'status' => $record->status, 'version' => $record->version,
        ], $this->tenant->id());

        return response()->json(['data' => $record]);
    }
}
