<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenancy\FeatureResolver;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\FeatureFlag;
use Illuminate\Http\JsonResponse;

class FeatureFlagController extends Controller
{
    public function index(TenantContext $context, FeatureResolver $resolver): JsonResponse
    {
        $agency = $context->agency();
        $flags = FeatureFlag::query()
            ->orderBy('key')
            ->pluck('key')
            ->mapWithKeys(fn (string $key) => [$key => $resolver->resolve($key, $agency)]);

        return response()->json(['data' => $flags]);
    }
}
