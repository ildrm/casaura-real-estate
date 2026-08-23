<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldMapping extends Model
{
    use HasUuids;

    protected $fillable = [
        'provider_connection_id', 'resource', 'version', 'fields',
        'created_by_user_id', 'activated_at',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ProviderConnection::class, 'provider_connection_id');
    }

    protected function casts(): array
    {
        return ['fields' => 'array', 'version' => 'integer', 'activated_at' => 'datetime'];
    }
}
