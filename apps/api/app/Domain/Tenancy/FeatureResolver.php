<?php

namespace App\Domain\Tenancy;

use App\Models\Agency;
use App\Models\FeatureFlag;
use Illuminate\Support\Carbon;

final class FeatureResolver
{
    public const GLOBAL_SCOPE_ID = '00000000-0000-0000-0000-000000000000';

    /** @return array{enabled: bool, value: mixed, source: string} */
    public function resolve(string $key, Agency $agency): array
    {
        $flag = FeatureFlag::query()->where('key', $key)->first();

        if (! $flag) {
            return ['enabled' => false, 'value' => null, 'source' => 'missing'];
        }

        $forceOff = array_filter(array_map('trim', explode(',', (string) config('features.force_off', ''))));
        if (in_array($key, $forceOff, true)) {
            return ['enabled' => false, 'value' => null, 'source' => 'environment'];
        }

        $environmentRule = data_get($flag->environment_rules, app()->environment());
        if (is_bool($environmentRule)) {
            return ['enabled' => $environmentRule, 'value' => null, 'source' => 'environment'];
        }

        $now = Carbon::now();
        $override = $flag->overrides()
            ->where('scope_type', 'agency')
            ->where('scope_id', $agency->getKey())
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->first();

        if ($override) {
            return ['enabled' => $override->enabled, 'value' => $override->value, 'source' => 'agency'];
        }

        $subscription = $agency->subscription()->with('plan.entitlements')->first();
        $entitlement = $subscription?->plan?->entitlements->firstWhere('key', $key);

        if ($entitlement) {
            $value = $entitlement->value;

            return [
                'enabled' => is_bool($value) ? $value : true,
                'value' => $value,
                'source' => 'plan',
            ];
        }

        $globalOverride = $flag->overrides()
            ->where('scope_type', 'global')
            ->where('scope_id', self::GLOBAL_SCOPE_ID)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->first();

        if ($globalOverride) {
            return ['enabled' => $globalOverride->enabled, 'value' => $globalOverride->value, 'source' => 'global_override'];
        }

        return ['enabled' => $flag->default_enabled, 'value' => null, 'source' => 'global'];
    }
}
