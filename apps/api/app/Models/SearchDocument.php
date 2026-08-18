<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SearchDocument extends Model
{
    protected $primaryKey = 'listing_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'projection_version' => 'integer',
            'price_amount_minor' => 'integer',
            'bedrooms' => 'integer',
            'bathrooms' => 'float',
            'interior_area_sqm' => 'float',
            'public_latitude' => 'float',
            'public_longitude' => 'float',
            'agency_verified' => 'boolean',
            'amenities' => 'array',
            'features' => 'array',
            'media' => 'array',
            'listed_at' => 'datetime',
        ];
    }
}
