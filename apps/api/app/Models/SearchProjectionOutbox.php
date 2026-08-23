<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SearchProjectionOutbox extends Model
{
    use HasUuids;

    protected $table = 'search_projection_outbox';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'projection_version' => 'integer',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
