<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasUuids;

    protected $fillable = ['namespace', 'key', 'value', 'is_secret', 'version'];

    public static function read(string $namespace, string $key, mixed $default = null): mixed
    {
        $setting = static::query()
            ->where('namespace', $namespace)
            ->where('key', $key)
            ->first();

        return $setting?->value ?? $default;
    }

    protected function casts(): array
    {
        return ['value' => 'json', 'is_secret' => 'boolean'];
    }
}
