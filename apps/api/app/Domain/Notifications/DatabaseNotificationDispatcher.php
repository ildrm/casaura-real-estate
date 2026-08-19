<?php

namespace App\Domain\Notifications;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DatabaseNotificationDispatcher implements NotificationDispatcher
{
    public function dispatch(string $userId, ?string $agencyId, string $type, string $title, ?string $body, array $data, string $deduplicationKey): void
    {
        DB::table('notifications')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'agency_id' => $agencyId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => json_encode($data, JSON_THROW_ON_ERROR),
            'deduplication_key' => $deduplicationKey,
            'created_at' => now(),
        ]);
    }
}
