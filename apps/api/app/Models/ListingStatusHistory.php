<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ListingStatusHistory extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'listing_status_history';

    protected $fillable = ['listing_id', 'from_status', 'to_status', 'actor_user_id', 'note', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
