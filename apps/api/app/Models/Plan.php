<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasUuids;

    protected $fillable = [
        'name', 'slug', 'is_active', 'is_public', 'price_amount_minor',
        'price_currency', 'billing_interval',
    ];

    public function entitlements(): HasMany
    {
        return $this->hasMany(PlanEntitlement::class);
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'price_amount_minor' => 'integer',
        ];
    }
}
