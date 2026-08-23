<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CollectionMember extends Model
{
    use HasUuids;

    protected $fillable = ['collection_id', 'user_id', 'role', 'accepted_at', 'revoked_at'];

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
