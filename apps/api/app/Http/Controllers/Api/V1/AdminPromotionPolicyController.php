<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\ApiException;
use App\Domain\Tenancy\AuditRecorder;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\PromotionPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminPromotionPolicyController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => PromotionPolicy::query()
                ->latest('created_at')->limit(100)->get()->map(fn (PromotionPolicy $policy) => $this->data($policy)),
            'meta' => [
                'plans' => Plan::query()->where('is_active', true)->orderBy('price_amount_minor')
                    ->get(['id', 'name', 'slug', 'price_amount_minor', 'price_currency', 'billing_interval']),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request);
        $this->assertPlans($validated['eligible_plan_ids']);
        $policy = DB::transaction(function () use ($request, $validated): PromotionPolicy {
            $policy = PromotionPolicy::query()->create([
                ...$validated,
                'family_id' => (string) Str::uuid(),
                'version' => 1,
                'status' => 'active',
                'created_by_user_id' => $request->user()->id,
            ]);
            $this->audit->record($request, 'promotion.policy_created', $policy, null, [
                'placement' => $policy->placement,
                'version' => $policy->version,
            ]);

            return $policy;
        });

        return response()->json(['data' => $this->data($policy)], 201);
    }

    public function update(Request $request, string $policy): JsonResponse
    {
        $current = PromotionPolicy::query()->findOrFail($policy);
        $validated = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'status' => ['required', Rule::in(['active', 'paused', 'ended'])],
            'ends_at' => ['nullable', 'date', 'after:now'],
        ]);
        if ($current->version !== (int) $validated['version']) {
            throw new ApiException('PROMOTION_POLICY_VERSION_CONFLICT', 'The promotion policy changed.', 409);
        }
        $replacement = DB::transaction(function () use ($request, $current, $validated): PromotionPolicy {
            $originalEndsAt = $current->ends_at->copy();
            $current->update([
                'status' => 'ended',
                'ends_at' => $current->ends_at->isBefore(now()) ? $current->ends_at : now(),
            ]);
            $replacement = PromotionPolicy::query()->create([
                ...$current->only([
                    'family_id', 'name', 'placement', 'label', 'disclosure',
                    'eligible_plan_ids', 'starts_at', 'ends_at', 'max_impressions',
                ]),
                'version' => $current->version + 1,
                'status' => $validated['status'],
                'ends_at' => $validated['ends_at'] ?? $originalEndsAt,
                'created_by_user_id' => $request->user()->id,
            ]);
            $this->audit->record($request, 'promotion.policy_versioned', $replacement, [
                'version' => $current->version, 'status' => $current->status,
            ], ['version' => $replacement->version, 'status' => $replacement->status]);

            return $replacement;
        });

        return response()->json(['data' => $this->data($replacement)]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'placement' => ['required', Rule::in(['search', 'detail', 'storefront'])],
            'label' => ['required', 'string', 'max:80'],
            'disclosure' => ['required', 'string', 'max:255'],
            'eligible_plan_ids' => ['required', 'array', 'min:1'],
            'eligible_plan_ids.*' => ['required', 'uuid', 'distinct'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'max_impressions' => ['required', 'integer', 'min:1', 'max:100000000'],
        ]);
    }

    /** @param list<string> $planIds */
    private function assertPlans(array $planIds): void
    {
        if (Plan::query()->where('is_active', true)->whereIn('id', $planIds)->count() !== count($planIds)) {
            throw new ApiException('PROMOTION_PLAN_INVALID', 'A promotion plan is unavailable.', 422);
        }
    }

    /** @return array<string, mixed> */
    private function data(PromotionPolicy $policy): array
    {
        return [
            'id' => $policy->id,
            'family_id' => $policy->family_id,
            'version' => $policy->version,
            'name' => $policy->name,
            'placement' => $policy->placement,
            'label' => $policy->label,
            'disclosure' => $policy->disclosure,
            'eligible_plan_ids' => $policy->eligible_plan_ids,
            'starts_at' => $policy->starts_at,
            'ends_at' => $policy->ends_at,
            'max_impressions' => $policy->max_impressions,
            'status' => $policy->status,
        ];
    }
}
