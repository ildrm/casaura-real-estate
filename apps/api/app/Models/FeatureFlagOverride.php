<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeatureFlagOverride extends Model
{
    use HasUuids;

    protected $fillable = [
        'feature_flag_id', 'scope_type', 'scope_id', 'enabled', 'value',
        'starts_at', 'ends_at',
    ];

    public function featureFlag(): BelongsTo
    {
        return $this->belongsTo(FeatureFlag::class);
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'value' => 'json',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
