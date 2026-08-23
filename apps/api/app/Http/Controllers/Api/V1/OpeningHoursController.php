<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\ApiException;
use App\Domain\Tenancy\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OpeningHoursController extends Controller
{
    public function index(TenantContext $tenant): JsonResponse
    {
        return response()->json(['data' => [
            'timezone' => $tenant->agency()->timezone,
            'hours' => DB::table('agency_opening_hours')->where('agency_id', $tenant->id())->orderBy('weekday')->get(),
            'closures' => DB::table('agency_closures')->where('agency_id', $tenant->id())->orderBy('date')->get(),
        ]]);
    }

    public function update(Request $request, TenantContext $tenant, AuditRecorder $audit): JsonResponse
    {
        $validated = $request->validate([
            'hours' => ['required', 'array', 'size:7'],
            'hours.*.weekday' => ['required', 'integer', 'between:0,6', 'distinct'],
            'hours.*.opens_at' => ['nullable', 'date_format:H:i'],
            'hours.*.closes_at' => ['nullable', 'date_format:H:i'],
            'hours.*.closed' => ['required', 'boolean'],
            'closures' => ['sometimes', 'array', 'max:100'],
            'closures.*.date' => ['required', 'date', 'distinct'],
            'closures.*.opens_at' => ['nullable', 'date_format:H:i'],
            'closures.*.closes_at' => ['nullable', 'date_format:H:i'],
            'closures.*.closed' => ['required', 'boolean'],
            'closures.*.reason' => ['nullable', 'string', 'max:200'],
        ]);
        foreach (array_merge($validated['hours'], $validated['closures'] ?? []) as $entry) {
            if (! $entry['closed'] && (empty($entry['opens_at']) || empty($entry['closes_at']) || $entry['closes_at'] <= $entry['opens_at'])) {
                throw new ApiException('OPENING_HOURS_INVALID', 'Open periods require a closing time after the opening time.');
            }
        }
        DB::transaction(function () use ($request, $tenant, $audit, $validated): void {
            DB::table('agency_opening_hours')->where('agency_id', $tenant->id())->delete();
            foreach ($validated['hours'] as $hour) {
                DB::table('agency_opening_hours')->insert([
                    'id' => (string) Str::uuid(), 'agency_id' => $tenant->id(), 'weekday' => $hour['weekday'],
                    'opens_at' => $hour['closed'] ? null : $hour['opens_at'], 'closes_at' => $hour['closed'] ? null : $hour['closes_at'],
                    'closed' => $hour['closed'], 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('agency_closures')->where('agency_id', $tenant->id())->delete();
            foreach ($validated['closures'] ?? [] as $closure) {
                DB::table('agency_closures')->insert([
                    'id' => (string) Str::uuid(), 'agency_id' => $tenant->id(), 'date' => $closure['date'],
                    'opens_at' => $closure['closed'] ? null : $closure['opens_at'], 'closes_at' => $closure['closed'] ? null : $closure['closes_at'],
                    'closed' => $closure['closed'], 'reason' => $closure['reason'] ?? null, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $audit->recordEntity($request, 'agency.opening_hours_updated', 'agency', $tenant->id(), null, [
                'hours_count' => count($validated['hours']), 'closures_count' => count($validated['closures'] ?? []),
            ], $tenant->id());
        });

        return $this->index($tenant);
    }
}
