<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\ApiException;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AgencyAnalyticsController extends Controller
{
    public function __invoke(Request $request, TenantContext $tenant): JsonResponse
    {
        $validated = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $from = isset($validated['from']) ? Carbon::parse($validated['from'])->startOfDay()->utc() : now()->subDays(29)->startOfDay();
        $to = isset($validated['to']) ? Carbon::parse($validated['to'])->endOfDay()->utc() : now()->endOfDay();
        if ($from->diffInDays($to) > 366) {
            throw new ApiException('ANALYTICS_RANGE_INVALID', 'Analytics ranges cannot exceed 366 days.');
        }
        $events = DB::table('analytics_events')->where('agency_id', $tenant->id())->whereBetween('occurred_at', [$from, $to]);

        return response()->json(['data' => [
            'range' => ['from' => $from->toISOString(), 'to' => $to->toISOString()],
            'storefront_views' => (clone $events)->where('type', 'storefront.view')->count(),
            'listing_views' => (clone $events)->where('type', 'listing.view')->count(),
            'favorites' => DB::table('favorites')->join('listings', 'listings.id', '=', 'favorites.listing_id')
                ->where('listings.agency_id', $tenant->id())->whereBetween('favorites.created_at', [$from, $to])->count(),
            'leads' => DB::table('leads')->where('agency_id', $tenant->id())->whereBetween('created_at', [$from, $to])->count(),
            'viewings' => DB::table('viewing_requests')->where('agency_id', $tenant->id())->whereBetween('created_at', [$from, $to])->count(),
            'newsletter_deliveries' => DB::table('newsletter_events')->join('newsletter_campaigns', 'newsletter_campaigns.id', '=', 'newsletter_events.campaign_id')
                ->where('newsletter_campaigns.agency_id', $tenant->id())->where('newsletter_events.event_type', 'delivered')
                ->whereBetween('newsletter_events.created_at', [$from, $to])->count(),
        ]]);
    }
}
