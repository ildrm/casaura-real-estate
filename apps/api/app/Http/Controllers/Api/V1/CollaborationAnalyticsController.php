<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class CollaborationAnalyticsController extends Controller
{
    public function __invoke(TenantContext $tenant): JsonResponse
    {
        $query = DB::table('leads')->where('agency_id', $tenant->id());
        $total = (clone $query)->count();
        $responded = (clone $query)->whereNotNull('first_responded_at')->count();
        $average = (clone $query)->whereNotNull('first_responded_at')->get(['created_at', 'first_responded_at'])
            ->avg(fn (object $lead) => max(0, strtotime($lead->first_responded_at) - strtotime($lead->created_at)));

        return response()->json(['data' => [
            'total_leads' => $total,
            'responded_leads' => $responded,
            'response_rate' => $total ? round(($responded / $total) * 100, 2) : 0.0,
            'average_first_response_seconds' => $responded ? (int) round((float) $average) : null,
        ]]);
    }
}
