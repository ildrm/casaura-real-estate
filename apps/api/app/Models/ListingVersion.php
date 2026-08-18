<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ListingVersion extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['listing_id', 'version', 'actor_user_id', 'snapshot', 'created_at'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'snapshot' => 'array', 'created_at' => 'datetime'];
    }
}
