<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenancy\AuditRecorder;
use App\Domain\Tenancy\FeatureResolver;
use App\Http\Controllers\Controller;
use App\Models\FeatureFlag;
use App\Models\FeatureFlagOverride;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminFeatureFlagController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string'],
        ]);
        $page = FeatureFlag::query()->with('overrides')->orderBy('key')->orderBy('id')
            ->cursorPaginate($validated['limit'] ?? 50);
        $flags = collect($page->items())->map(fn (FeatureFlag $flag) => [
            'id' => $flag->id, 'key' => $flag->key, 'description' => $flag->description,
            'default_enabled' => $flag->default_enabled,
            'overrides' => $flag->overrides->map(fn (FeatureFlagOverride $override) => $this->projection($override)),
        ]);

        return response()->json(['data' => $flags, 'meta' => ['next_cursor' => $page->nextCursor()?->encode()]]);
    }

    public function updateOverride(Request $request, string $flag, AuditRecorder $audit): JsonResponse
    {
        $record = FeatureFlag::query()->findOrFail($flag);
        $validated = $request->validate([
            'scope_type' => ['required', Rule::in(['global', 'agency'])],
            'scope_id' => ['nullable', 'uuid'], 'enabled' => ['required', 'boolean'], 'value' => ['nullable'],
            'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ]);
        if ($validated['scope_type'] === 'agency') {
            $request->validate(['scope_id' => ['required', 'uuid', 'exists:agencies,id']]);
        }
        $validated['scope_id'] = $validated['scope_type'] === 'global'
            ? FeatureResolver::GLOBAL_SCOPE_ID
            : $validated['scope_id'];
        $override = DB::transaction(function () use ($request, $record, $validated, $audit): FeatureFlagOverride {
            FeatureFlag::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $existing = FeatureFlagOverride::query()->where([
                'feature_flag_id' => $record->id,
                'scope_type' => $validated['scope_type'],
                'scope_id' => $validated['scope_id'],
            ])->first();
            $override = FeatureFlagOverride::query()->updateOrCreate(
                ['feature_flag_id' => $record->id, 'scope_type' => $validated['scope_type'], 'scope_id' => $validated['scope_id']],
                ['enabled' => $validated['enabled'], 'value' => $validated['value'] ?? null, 'starts_at' => $validated['starts_at'] ?? null, 'ends_at' => $validated['ends_at'] ?? null],
            );
            $before = $existing ? [
                'scope_type' => $existing->scope_type, 'scope_id' => $existing->scope_id,
                'enabled' => $existing->enabled, 'starts_at' => $existing->starts_at,
                'ends_at' => $existing->ends_at,
            ] : null;
            $audit->recordEntity($request, 'feature_flag.override_updated', 'feature_flag_override', $override->id, $before, [
                'flag_key' => $record->key, 'scope_type' => $override->scope_type, 'scope_id' => $override->scope_id, 'enabled' => $override->enabled,
            ], $override->scope_type === 'agency' ? $override->scope_id : null);

            return $override;
        });

        return response()->json(['data' => $this->projection($override)]);
    }

    public function destroyOverride(Request $request, string $flag, string $override, AuditRecorder $audit): JsonResponse
    {
        $record = FeatureFlagOverride::query()->where('feature_flag_id', $flag)->findOrFail($override);
        DB::transaction(function () use ($request, $audit, $record): void {
            $audit->recordEntity($request, 'feature_flag.override_deleted', 'feature_flag_override', $record->id, [
                'scope_type' => $record->scope_type, 'scope_id' => $record->scope_id, 'enabled' => $record->enabled,
            ], null, $record->scope_type === 'agency' ? $record->scope_id : null);
            $record->delete();
        });

        return response()->json(status: 204);
    }

    /** @return array<string, mixed> */
    private function projection(FeatureFlagOverride $override): array
    {
        return [
            'id' => $override->id, 'scope_type' => $override->scope_type,
            'scope_id' => $override->scope_type === 'global' ? null : $override->scope_id,
            'enabled' => $override->enabled, 'value' => $override->value,
            'starts_at' => $override->starts_at?->toISOString(), 'ends_at' => $override->ends_at?->toISOString(),
        ];
    }
}
