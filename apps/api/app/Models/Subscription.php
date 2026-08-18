<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    use HasUuids;

    protected $fillable = [
        'agency_id', 'plan_id', 'status', 'billing_status', 'promotional_starts_at',
        'promotional_ends_at', 'trial_ends_at', 'free_until', 'current_period_ends_at',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    protected function casts(): array
    {
        return [
            'promotional_starts_at' => 'datetime',
            'promotional_ends_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'free_until' => 'datetime',
            'current_period_ends_at' => 'datetime',
        ];
    }
}
