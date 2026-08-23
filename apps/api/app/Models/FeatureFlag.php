<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeatureFlag extends Model
{
    use HasUuids;

    protected $fillable = ['key', 'description', 'default_enabled', 'environment_rules'];

    public function overrides(): HasMany
    {
        return $this->hasMany(FeatureFlagOverride::class);
    }

    protected function casts(): array
    {
        return ['default_enabled' => 'boolean', 'environment_rules' => 'json'];
    }
}
