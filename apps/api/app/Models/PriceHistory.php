<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PriceHistory extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'price_history';

    protected $fillable = ['listing_id', 'amount_minor', 'currency', 'actor_user_id', 'effective_at'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'effective_at' => 'datetime'];
    }
}
