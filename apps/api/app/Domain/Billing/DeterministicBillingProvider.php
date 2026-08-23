<?php

namespace App\Domain\Billing;

use App\Models\Agency;
use App\Models\Plan;

final class DeterministicBillingProvider implements BillingProvider
{
    public function createCustomer(Agency $agency, string $idempotencyKey): array
    {
        return ['id' => 'cus_det_'.substr(hash('sha256', $agency->id), 0, 24)];
    }

    public function createCheckout(Agency $agency, Plan $plan, string $customerId, string $idempotencyKey): array
    {
        $id = 'cs_det_'.substr(hash('sha256', $agency->id.'|'.$plan->id.'|'.$idempotencyKey), 0, 24);

        return ['id' => $id, 'url' => "https://checkout.stripe.test/{$id}", 'expires_at' => now()->addMinutes(30)->toISOString()];
    }

    public function createPortal(string $customerId): array
    {
        $id = 'bps_det_'.substr(hash('sha256', $customerId.'|'.now()->timestamp), 0, 24);

        return ['id' => $id, 'url' => "https://billing.stripe.test/{$id}"];
    }

    public function adapter(): string
    {
        return 'deterministic';
    }
}
