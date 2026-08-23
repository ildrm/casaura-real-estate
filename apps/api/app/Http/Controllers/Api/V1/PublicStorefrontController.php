<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Analytics\AnalyticsRecorder;
use App\Domain\Search\PublicListingPresenter;
use App\Domain\Tenancy\FeatureResolver;
use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\SearchDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PublicStorefrontController extends Controller
{
    public function __invoke(
        Request $request,
        string $agency,
        FeatureResolver $features,
        PublicListingPresenter $presenter,
        AnalyticsRecorder $analytics,
    ): JsonResponse {
        $record = Agency::query()->where('slug', $agency)->where('status', 'active')->firstOrFail();
        abort_unless($features->resolve('agency_storefronts', $record)['enabled'], 404);
        $hours = DB::table('agency_opening_hours')->where('agency_id', $record->id)->orderBy('weekday')->get()
            ->map(fn (object $hour) => [
                'weekday' => (int) $hour->weekday, 'opens_at' => $hour->opens_at,
                'closes_at' => $hour->closes_at, 'closed' => (bool) $hour->closed,
            ]);
        $team = DB::table('agency_members')->join('users', 'users.id', '=', 'agency_members.user_id')
            ->where('agency_members.agency_id', $record->id)->where('agency_members.status', 'active')
            ->where('agency_members.is_public', true)
            ->orderByRaw('CASE WHEN agency_members.public_position IS NULL THEN 1 ELSE 0 END')
            ->orderBy('agency_members.public_position')->orderBy('agency_members.created_at')
            ->orderBy('agency_members.id')
            ->get(['agency_members.id', 'agency_members.job_title', 'users.name'])
            ->map(fn (object $member) => ['id' => $member->id, 'name' => $member->name, 'job_title' => $member->job_title]);
        $listings = SearchDocument::query()->where('agency_id', $record->id)->where('status', 'published')
            ->orderByDesc('listed_at')->limit(50)->get()->map(fn (SearchDocument $document) => $presenter->card($document));
        $analytics->recordPublicView($request, $record->id, 'storefront.view', null, ['surface' => 'storefront']);

        return response()->json(['data' => [
            'agency' => [
                'id' => $record->id, 'name' => $record->name, 'slug' => $record->slug,
                'phone' => $record->phone, 'website' => $record->website,
                'short_description' => $record->short_description, 'description' => $record->description,
                'timezone' => $record->timezone, 'verified' => $record->verification_status === 'verified',
            ],
            'opening_hours' => $hours, 'team' => $team, 'listings' => $listings,
        ]]);
    }
}
