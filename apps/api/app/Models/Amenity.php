<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Amenity extends Model
{
    use HasUuids;

    protected $fillable = ['slug', 'name', 'group', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
