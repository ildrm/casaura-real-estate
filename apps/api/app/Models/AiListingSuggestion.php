<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiListingSuggestion extends Model
{
    use HasUuids;

    protected $fillable = [
        'agency_id', 'listing_id', 'ai_generation_id', 'source_listing_version',
        'suggested_fields', 'applied_fields', 'applied_by_user_id', 'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'source_listing_version' => 'integer',
            'suggested_fields' => 'array',
            'applied_fields' => 'array',
            'applied_at' => 'datetime',
        ];
    }
}
