<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Address extends Model
{
    use HasUuids;

    protected $fillable = [
        'agency_id', 'line_1', 'line_2', 'locality', 'region', 'postal_code',
        'country_code', 'normalized', 'latitude', 'longitude', 'public_location_policy',
        'public_latitude', 'public_longitude',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'public_latitude' => 'decimal:7',
            'public_longitude' => 'decimal:7',
        ];
    }
}
