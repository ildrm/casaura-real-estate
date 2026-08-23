<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ConsumerCollection extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'collections';

    protected $fillable = ['owner_user_id', 'name', 'version'];

    public function members(): HasMany
    {
        return $this->hasMany(CollectionMember::class, 'collection_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CollectionProperty::class, 'collection_id')->orderBy('position');
    }

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
