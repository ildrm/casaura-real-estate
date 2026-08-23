<?php

namespace App\Domain\Identity;

use App\Models\User;

final class IdentityPolicy
{
    /** @var list<string> */
    private const MFA_REQUIRED_ROLES = [
        'agency_owner',
        'platform_administrator',
        'super_administrator',
        'moderator',
        'support_administrator',
    ];

    public function requiresMfa(User $user): bool
    {
        return $user->memberships()
            ->where('agency_members.status', 'active')
            ->whereHas('agency', fn ($query) => $query->where('status', 'active'))
            ->whereHas('roles', fn ($query) => $query
                ->where('roles.scope', 'platform')
                ->where('roles.is_system', true)
                ->whereIn('roles.slug', self::MFA_REQUIRED_ROLES))
            ->exists();
    }
}
