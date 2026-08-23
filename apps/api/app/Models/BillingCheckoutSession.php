<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BillingCheckoutSession extends Model
{
    use HasUuids;

    protected $fillable = [
        'agency_id', 'plan_id', 'actor_user_id', 'idempotency_key', 'payload_hash',
        'provider_session_id', 'status', 'url', 'expires_at',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
