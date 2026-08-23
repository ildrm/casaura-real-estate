<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DataSubjectRequest extends Model
{
    use HasUuids;

    protected $fillable = [
        'subject_user_id', 'requested_by_user_id', 'type', 'status', 'operator_reference',
        'output_storage_key', 'output_checksum_sha256', 'failure_code', 'requested_at',
        'started_at', 'completed_at', 'expires_at',
    ];

    protected $hidden = ['operator_reference', 'output_storage_key', 'output_checksum_sha256', 'failure_code'];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
