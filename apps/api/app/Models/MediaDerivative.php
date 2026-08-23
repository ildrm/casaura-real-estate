<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaDerivative extends Model
{
    use HasUuids;

    protected $fillable = ['media_id', 'kind', 'storage_key', 'mime_type', 'byte_size', 'width', 'height'];

    protected $hidden = ['storage_key'];

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    protected function casts(): array
    {
        return ['byte_size' => 'integer', 'width' => 'integer', 'height' => 'integer'];
    }
}
