<?php

namespace App\Domain\Billing;

use App\Models\Agency;
use App\Models\Plan;

interface BillingProvider
{
    /** @return array{id: string} */
    public function createCustomer(Agency $agency, string $idempotencyKey): array;

    /** @return array{id: string, url: string, expires_at: ?string} */
    public function createCheckout(Agency $agency, Plan $plan, string $customerId, string $idempotencyKey): array;

    /** @return array{id: string, url: string} */
    public function createPortal(string $customerId): array;

    public function adapter(): string;
}
