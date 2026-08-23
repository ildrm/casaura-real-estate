<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CollectionProperty extends Model
{
    use HasUuids;

    protected $fillable = ['collection_id', 'listing_id', 'added_by_user_id', 'position'];

    public function listing(): BelongsTo
    {
        return $this->belongsTo(SearchDocument::class, 'listing_id', 'listing_id');
    }

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }
}
