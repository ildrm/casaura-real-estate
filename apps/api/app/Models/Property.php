<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Property extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'agency_id', 'property_type_id', 'address_id', 'bedrooms', 'bathrooms', 'interior_area_sqm',
    ];

    public function propertyType(): BelongsTo
    {
        return $this->belongsTo(PropertyType::class);
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function featureValues(): HasMany
    {
        return $this->hasMany(PropertyFeatureValue::class);
    }

    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class, 'property_amenities');
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    protected function casts(): array
    {
        return ['bedrooms' => 'integer', 'bathrooms' => 'decimal:1', 'interior_area_sqm' => 'decimal:2'];
    }
}
