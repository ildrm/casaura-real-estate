<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncJob extends Model
{
    use HasUuids;

    protected $fillable = [
        'provider_connection_id', 'mode', 'status', 'idempotency_key', 'payload_hash',
        'start_cursor', 'end_cursor', 'records_fetched', 'records_imported',
        'records_skipped', 'records_failed', 'failure_code', 'started_at', 'completed_at',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ProviderConnection::class, 'provider_connection_id');
    }

    protected function casts(): array
    {
        return [
            'records_fetched' => 'integer',
            'records_imported' => 'integer',
            'records_skipped' => 'integer',
            'records_failed' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
