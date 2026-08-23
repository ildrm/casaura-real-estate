<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AgencyMember extends Model
{
    use HasUuids;

    protected $fillable = [
        'agency_id', 'user_id', 'status', 'job_title', 'invited_by_user_id',
        'invitation_token_hash', 'invitation_expires_at', 'invitation_cancelled_at',
        'invited_user_was_created', 'is_public', 'public_position', 'invited_at', 'accepted_at',
    ];

    protected $hidden = ['invitation_token_hash'];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'member_roles');
    }

    public function hasPermission(string $permission): bool
    {
        return $this->roles()
            ->whereHas('permissions', fn ($query) => $query->where('name', $permission))
            ->exists();
    }

    /** @return list<string> */
    public function permissionNames(): array
    {
        return $this->roles
            ->flatMap(fn (Role $role) => $role->permissions->pluck('name'))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    protected function casts(): array
    {
        return [
            'invited_at' => 'datetime',
            'accepted_at' => 'datetime',
            'invitation_expires_at' => 'datetime',
            'invitation_cancelled_at' => 'datetime',
            'invited_user_was_created' => 'boolean',
            'is_public' => 'boolean',
            'public_position' => 'integer',
        ];
    }
}
