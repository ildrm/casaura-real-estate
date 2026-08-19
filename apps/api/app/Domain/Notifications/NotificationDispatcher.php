<?php

namespace App\Domain\Notifications;

interface NotificationDispatcher
{
    /** @param array<string, mixed> $data */
    public function dispatch(string $userId, ?string $agencyId, string $type, string $title, ?string $body, array $data, string $deduplicationKey): void;
}
