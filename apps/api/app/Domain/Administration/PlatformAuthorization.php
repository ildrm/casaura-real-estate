<?php

namespace App\Domain\Administration;

use App\Models\User;

final class PlatformAuthorization
{
    /** @var list<string> */
    private const PLATFORM_ROLES = ['moderator', 'support_administrator', 'platform_administrator', 'super_administrator'];

    public function allows(User $user, string $permission): bool
    {
        return $user->memberships()
            ->where('status', 'active')
            ->whereHas('agency', fn ($agency) => $agency->where('status', 'active'))
            ->whereHas('roles', fn ($roles) => $roles
                ->where('scope', 'platform')
                ->where('is_system', true)
                ->whereIn('slug', self::PLATFORM_ROLES)
                ->whereHas('permissions', fn ($permissions) => $permissions->where('name', $permission)))
            ->exists();
    }
}
