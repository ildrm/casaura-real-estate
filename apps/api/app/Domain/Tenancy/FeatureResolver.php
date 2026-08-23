<?php

namespace App\Domain\Tenancy;

use App\Domain\ApiException;
use App\Models\Agency;
use App\Models\FeatureFlag;
use App\Models\FeatureFlagOverride;
use App\Models\PlanEntitlement;
use App\Models\Subscription;
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

        if ($this->isForcedOff($key)) {
            return ['enabled' => false, 'value' => null, 'source' => 'environment'];
        }

        $environmentRule = data_get($flag->environment_rules, app()->environment());
        if ($environmentRule === false) {
            return ['enabled' => false, 'value' => null, 'source' => 'environment'];
        }

        $now = Carbon::now();
        $planGated = PlanEntitlement::query()->where('key', $key)->exists();
        $subscription = $planGated ? $this->eligibleSubscription($agency, $now) : null;

        if ($planGated && ! $subscription) {
            return ['enabled' => false, 'value' => null, 'source' => 'subscription'];
        }

        if ($environmentRule === true) {
            return ['enabled' => true, 'value' => null, 'source' => 'environment'];
        }

        $override = $this->activeOverride($flag, 'agency', (string) $agency->getKey(), $now);

        if ($override) {
            return ['enabled' => $override->enabled, 'value' => $override->value, 'source' => 'agency'];
        }

        $entitlement = $subscription?->plan?->entitlements->firstWhere('key', $key);

        if ($entitlement) {
            $value = $entitlement->value;

            return [
                'enabled' => is_bool($value) ? $value : true,
                'value' => $value,
                'source' => 'plan',
            ];
        }

        if ($planGated) {
            return ['enabled' => false, 'value' => null, 'source' => 'plan'];
        }

        $globalOverride = $this->activeOverride($flag, 'global', self::GLOBAL_SCOPE_ID, $now);

        if ($globalOverride) {
            return ['enabled' => $globalOverride->enabled, 'value' => $globalOverride->value, 'source' => 'global_override'];
        }

        return ['enabled' => $flag->default_enabled, 'value' => null, 'source' => 'global'];
    }

    /** @return array{enabled: bool, value: mixed, source: string} */
    public function resolveGlobal(string $key): array
    {
        $flag = FeatureFlag::query()->where('key', $key)->first();

        if (! $flag) {
            return ['enabled' => false, 'value' => null, 'source' => 'missing'];
        }

        if ($this->isForcedOff($key)) {
            return ['enabled' => false, 'value' => null, 'source' => 'environment'];
        }

        $environmentRule = data_get($flag->environment_rules, app()->environment());
        if (is_bool($environmentRule)) {
            return ['enabled' => $environmentRule, 'value' => null, 'source' => 'environment'];
        }

        $globalOverride = $this->activeOverride($flag, 'global', self::GLOBAL_SCOPE_ID, Carbon::now());
        if ($globalOverride) {
            return ['enabled' => $globalOverride->enabled, 'value' => $globalOverride->value, 'source' => 'global_override'];
        }

        return ['enabled' => $flag->default_enabled, 'value' => null, 'source' => 'global'];
    }

    public function ensureEnabled(string $key, ?Agency $agency = null): void
    {
        $resolution = $agency ? $this->resolve($key, $agency) : $this->resolveGlobal($key);

        if (! $resolution['enabled']) {
            throw new ApiException('FEATURE_DISABLED', 'This feature is not available.', 403);
        }
    }

    public function quota(string $key, Agency $agency): ?int
    {
        $subscription = $this->eligibleSubscription($agency, Carbon::now());
        $entitlement = $subscription?->plan?->entitlements->firstWhere('key', $key);

        return $entitlement?->quota;
    }

    private function isForcedOff(string $key): bool
    {
        $forceOff = array_filter(array_map('trim', explode(',', (string) config('features.force_off', ''))));

        return in_array($key, $forceOff, true);
    }

    private function eligibleSubscription(Agency $agency, Carbon $now): ?Subscription
    {
        $subscription = $agency->subscription()->with('plan.entitlements')->first();

        if (! $subscription || $subscription->status !== 'active' || ! $subscription->plan?->is_active) {
            return null;
        }

        if (! in_array($subscription->billing_status, ['not_required', 'paid', 'trialing'], true)) {
            return null;
        }

        if ($subscription->current_period_ends_at?->lessThanOrEqualTo($now)) {
            return null;
        }

        return $subscription;
    }

    private function activeOverride(
        FeatureFlag $flag,
        string $scopeType,
        string $scopeId,
        Carbon $now,
    ): ?FeatureFlagOverride {
        return $flag->overrides()
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->first();
    }
}
