<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiGeneration extends Model
{
    use HasUuids;

    protected $fillable = [
        'ai_session_id', 'agency_id', 'listing_id', 'adapter', 'model', 'purpose',
        'status', 'prompt_hash', 'parsed_filters', 'output', 'latency_ms',
        'input_tokens', 'output_tokens', 'safety_code', 'content_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'parsed_filters' => 'array',
            'output' => 'array',
            'latency_ms' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'content_expires_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AiSession::class, 'ai_session_id');
    }
}
