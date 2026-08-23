<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RealEstateDataProvider extends Model
{
    use HasUuids;

    protected $fillable = ['key', 'name', 'adapter', 'protocol', 'is_active', 'capabilities'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'capabilities' => 'array'];
    }
}
