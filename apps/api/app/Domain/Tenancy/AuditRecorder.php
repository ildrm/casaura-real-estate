<?php

namespace App\Domain\Tenancy;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class AuditRecorder
{
    /** @param array<string, mixed>|null $before @param array<string, mixed>|null $after */
    public function record(
        Request $request,
        string $action,
        ?Model $entity = null,
        ?array $before = null,
        ?array $after = null,
        ?string $agencyId = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'actor_user_id' => $request->user()?->getAuthIdentifier(),
            'agency_id' => $agencyId,
            'action' => $action,
            'entity_type' => $entity ? $entity::class : null,
            'entity_id' => $entity?->getKey(),
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'request_id' => $request->attributes->get('request_id'),
            'created_at' => now(),
        ]);
    }
}
