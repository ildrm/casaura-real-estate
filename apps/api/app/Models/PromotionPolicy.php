<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PromotionPolicy extends Model
{
    use HasUuids;

    protected $fillable = [
        'family_id', 'version', 'name', 'placement', 'label', 'disclosure',
        'eligible_plan_ids', 'starts_at', 'ends_at', 'max_impressions', 'status',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'eligible_plan_ids' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'max_impressions' => 'integer',
        ];
    }
}
