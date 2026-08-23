<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected static function booted(): void
    {
        static::creating(function (self $token): void {
            if ($token->tokenable instanceof User) {
                $token->security_version = $token->tokenable->security_version;
            }
        });
    }

    protected function casts(): array
    {
        return [...parent::casts(), 'security_version' => 'integer'];
    }
}
