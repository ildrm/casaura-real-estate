<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiSession extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'purpose', 'status', 'content_expires_at'];

    protected function casts(): array
    {
        return ['content_expires_at' => 'datetime'];
    }
}
