<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PropertyIdentifier extends Model
{
    use HasUuids;

    protected $fillable = ['property_id', 'scheme', 'value', 'source'];
}
