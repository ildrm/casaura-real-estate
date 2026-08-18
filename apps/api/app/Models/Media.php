<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Media extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'media';

    protected $fillable = [
        'agency_id', 'listing_id', 'idempotency_key', 'original_name', 'mime_type',
        'byte_size', 'width', 'height', 'position', 'checksum_sha256', 'storage_key', 'alt_text',
    ];

    protected $hidden = ['storage_key', 'checksum_sha256', 'idempotency_key'];

    public function derivatives(): HasMany
    {
        return $this->hasMany(MediaDerivative::class);
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    protected function casts(): array
    {
        return ['byte_size' => 'integer', 'width' => 'integer', 'height' => 'integer', 'position' => 'integer'];
    }
}
