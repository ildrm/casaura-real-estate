<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProviderConnection extends Model
{
    use HasUuids;

    protected $fillable = [
        'agency_id', 'provider_id', 'name', 'base_url', 'token_url', 'client_id',
        'secret_reference', 'resources', 'rights_snapshot', 'data_dictionary_version',
        'is_enabled', 'version', 'last_sync_status', 'last_synced_at',
    ];

    protected $hidden = ['client_id'];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(RealEstateDataProvider::class, 'provider_id');
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(FieldMapping::class);
    }

    protected function casts(): array
    {
        return [
            'resources' => 'array',
            'rights_snapshot' => 'array',
            'is_enabled' => 'boolean',
            'version' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }
}
