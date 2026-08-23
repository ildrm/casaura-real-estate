<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyFeatureValue extends Model
{
    use HasUuids;

    protected $fillable = ['property_id', 'property_feature_definition_id', 'value'];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(PropertyFeatureDefinition::class, 'property_feature_definition_id');
    }

    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
