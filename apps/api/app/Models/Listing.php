<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Listing extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'agency_id', 'property_id', 'created_by_user_id', 'reviewed_by_user_id',
        'reference', 'slug', 'intent', 'status', 'title', 'description', 'price_amount_minor',
        'price_currency', 'version', 'quality_score', 'submitted_at', 'published_at', 'withdrawn_at',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class)->orderBy('position');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ListingVersion::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(ListingStatusHistory::class);
    }

    public function priceHistory(): HasMany
    {
        return $this->hasMany(PriceHistory::class);
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(PropertyReaction::class);
    }

    protected function casts(): array
    {
        return [
            'price_amount_minor' => 'integer',
            'version' => 'integer',
            'quality_score' => 'integer',
            'submitted_at' => 'datetime',
            'published_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }
}
