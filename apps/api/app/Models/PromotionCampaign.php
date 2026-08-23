<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromotionCampaign extends Model
{
    use HasUuids;

    protected $fillable = [
        'agency_id', 'listing_id', 'promotion_policy_id', 'placement',
        'starts_at', 'ends_at', 'impression_cap', 'impression_count', 'status', 'version',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'impression_cap' => 'integer',
            'impression_count' => 'integer',
            'version' => 'integer',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(PromotionPolicy::class, 'promotion_policy_id');
    }
}
