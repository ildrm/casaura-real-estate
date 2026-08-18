<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PropertyFeatureDefinition extends Model
{
    use HasUuids;

    protected $fillable = ['slug', 'name', 'value_type', 'unit', 'validation', 'is_active'];

    protected function casts(): array
    {
        return ['validation' => 'array', 'is_active' => 'boolean'];
    }
}
