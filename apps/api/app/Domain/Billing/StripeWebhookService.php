<?php

namespace App\Domain\Billing;

use App\Domain\ApiException;
use App\Models\BillingCustomer;
use App\Models\Invoice;
use App\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StripeWebhookService
{
    /** @return array<string, mixed> */
    public function verify(string $payload, string $header): array
    {
        $secret = (string) config('billing.stripe.webhook_secret');
        if ($secret === '') {
            throw new ApiException('BILLING_WEBHOOK_UNCONFIGURED', 'The billing webhook is not configured.', 503);
        }
        $parts = collect(explode(',', $header))->mapWithKeys(function (string $part): array {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);

            return $key && $value ? [$key => $value] : [];
        });
        $timestamp = (int) $parts->get('t', 0);
        $signature = (string) $parts->get('v1', '');
        $tolerance = (int) config('billing.stripe.webhook_tolerance_seconds', 300);
        if ($timestamp <= 0 || abs(now()->timestamp - $timestamp) > $tolerance ||
            $signature === '' || ! hash_equals(hash_hmac('sha256', $timestamp.'.'.$payload, $secret), $signature)) {
            throw new ApiException('BILLING_WEBHOOK_SIGNATURE_INVALID', 'The billing webhook signature is invalid.', 400);
        }
        $event = json_decode($payload, true);
        if (! is_array($event) || ! is_string($event['id'] ?? null) || ! is_string($event['type'] ?? null)) {
            throw new ApiException('BILLING_WEBHOOK_PAYLOAD_INVALID', 'The billing webhook payload is invalid.', 400);
        }

        return $event;
    }

    /** @param array<string, mixed> $event */
    public function process(array $event, string $payload): bool
    {
        return DB::transaction(function () use ($event, $payload): bool {
            $existing = DB::table('billing_events')->where('provider', 'stripe')
                ->where('provider_event_id', $event['id'])->lockForUpdate()->first();
            if ($existing) {
                return false;
            }
            $object = data_get($event, 'data.object', []);
            $object = is_array($object) ? $object : [];
            $customerId = $this->customerId($event, $object);
            $customer = is_string($customerId)
                ? BillingCustomer::query()->where('provider', 'stripe')->where('provider_customer_id', $customerId)->first()
                : null;
            $providerObjectId = is_string($object['id'] ?? null) ? $object['id'] : null;
            $eventId = (string) Str::uuid();
            DB::table('billing_events')->insert([
                'id' => $eventId,
                'provider' => 'stripe',
                'provider_event_id' => $event['id'],
                'event_type' => $event['type'],
                'provider_created_at' => Carbon::createFromTimestampUTC((int) ($event['created'] ?? now()->timestamp)),
                'payload_hash' => hash('sha256', $payload),
                'provider_object_id' => $providerObjectId,
                'provider_customer_id' => $customerId,
                'summary' => json_encode([
                    'livemode' => (bool) ($event['livemode'] ?? false),
                    'object_type' => is_string($object['object'] ?? null) ? $object['object'] : null,
                ], JSON_THROW_ON_ERROR),
                'agency_id' => $customer?->agency_id,
                'status' => $customer ? 'processing' : 'unresolved',
                'failure_code' => $customer ? null : 'BILLING_CUSTOMER_UNKNOWN',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if (! $customer) {
                return true;
            }
            $eventTimestamp = (int) ($event['created'] ?? now()->timestamp);
            match (true) {
                str_starts_with($event['type'], 'invoice.') => $this->invoice($customer->agency_id, $object, $eventTimestamp),
                str_starts_with($event['type'], 'customer.subscription.') => $this->subscription($customer->agency_id, $object, $eventTimestamp),
                str_starts_with($event['type'], 'checkout.session.') => $this->checkout($customer->agency_id, $event['type'], $object, $eventTimestamp),
                $event['type'] === 'customer.deleted' => $this->setBillingStatus($customer->agency_id, 'canceled', $eventTimestamp, false),
                in_array($event['type'], ['payment_intent.payment_failed', 'payment_intent.canceled'], true) => $this->setBillingStatus($customer->agency_id, 'past_due', $eventTimestamp),
                $event['type'] === 'payment_intent.succeeded' => $this->setBillingStatus($customer->agency_id, 'paid', $eventTimestamp),
                str_starts_with($event['type'], 'charge.dispute.') => $this->dispute($customer->agency_id, $object, $eventTimestamp),
                $event['type'] === 'charge.refunded', str_starts_with($event['type'], 'refund.') => $this->setBillingStatus($customer->agency_id, 'unpaid', $eventTimestamp),
                default => null,
            };
            DB::table('billing_events')->where('id', $eventId)->update([
                'status' => 'processed',
                'processed_at' => now(),
                'updated_at' => now(),
            ]);

            return true;
        });
    }

    /** @param array<string, mixed> $event @param array<string, mixed> $object */
    private function customerId(array $event, array $object): ?string
    {
        if (is_string($object['customer'] ?? null) && $object['customer'] !== '') {
            return $object['customer'];
        }
        if (str_starts_with((string) ($event['type'] ?? ''), 'customer.') &&
            ! str_starts_with((string) ($event['type'] ?? ''), 'customer.subscription.') &&
            is_string($object['id'] ?? null)) {
            return $object['id'];
        }
        foreach (['payment_intent', 'charge'] as $relatedField) {
            $relatedId = $object[$relatedField] ?? null;
            if (! is_string($relatedId) || $relatedId === '') {
                continue;
            }
            $resolved = DB::table('billing_events')->where('provider', 'stripe')
                ->where('provider_object_id', $relatedId)->whereNotNull('provider_customer_id')
                ->latest('provider_created_at')->value('provider_customer_id');
            if (is_string($resolved) && $resolved !== '') {
                return $resolved;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $checkout */
    private function checkout(string $agencyId, string $eventType, array $checkout, int $eventTimestamp): void
    {
        $status = match ($eventType) {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => 'completed',
            'checkout.session.expired' => 'expired',
            'checkout.session.async_payment_failed' => 'failed',
            default => 'pending',
        };
        DB::table('billing_checkout_sessions')->where('agency_id', $agencyId)
            ->where('provider_session_id', (string) ($checkout['id'] ?? ''))
            ->update(['status' => $status, 'updated_at' => now()]);
        $subscription = Subscription::query()->where('agency_id', $agencyId)->first();
        $updatedAt = Carbon::createFromTimestampUTC($eventTimestamp);
        if ($subscription && ! $subscription->provider_updated_at?->greaterThan($updatedAt)) {
            $subscription->update([
                'provider' => 'stripe',
                'provider_customer_id' => $checkout['customer'] ?? $subscription->provider_customer_id,
                'provider_subscription_id' => $checkout['subscription'] ?? $subscription->provider_subscription_id,
                'provider_updated_at' => $updatedAt,
            ]);
        }
    }

    /** @param array<string, mixed> $dispute */
    private function dispute(string $agencyId, array $dispute, int $eventTimestamp): void
    {
        $status = match ((string) ($dispute['status'] ?? '')) {
            'won', 'warning_closed' => 'paid',
            'needs_response', 'under_review', 'warning_needs_response', 'warning_under_review' => 'past_due',
            default => 'unpaid',
        };
        $this->setBillingStatus($agencyId, $status, $eventTimestamp);
    }

    private function setBillingStatus(string $agencyId, string $billingStatus, int $eventTimestamp, ?bool $active = null): void
    {
        $subscription = Subscription::query()->where('agency_id', $agencyId)->first();
        if (! $subscription) {
            return;
        }
        $updatedAt = Carbon::createFromTimestampUTC($eventTimestamp);
        if ($subscription->provider_updated_at?->greaterThan($updatedAt)) {
            return;
        }
        $attributes = [
            'provider' => 'stripe',
            'billing_status' => $billingStatus,
            'provider_updated_at' => $updatedAt,
        ];
        if ($active !== null) {
            $attributes['status'] = $active ? 'active' : 'inactive';
        }
        if ($billingStatus === 'canceled') {
            $attributes['canceled_at'] = $updatedAt;
        }
        $subscription->update($attributes);
    }

    /** @param array<string, mixed> $invoice */
    private function invoice(string $agencyId, array $invoice, int $eventTimestamp): void
    {
        $subscription = Subscription::query()->where('agency_id', $agencyId)->first();
        $existing = Invoice::query()->where('provider', 'stripe')
            ->where('provider_invoice_id', (string) ($invoice['id'] ?? ''))->first();
        $updatedAt = Carbon::createFromTimestampUTC($eventTimestamp);
        if ($existing?->provider_updated_at?->greaterThan($updatedAt)) {
            return;
        }
        $tax = isset($invoice['tax']) ? (int) $invoice['tax'] : collect($invoice['total_tax_amounts'] ?? [])
            ->sum(fn ($item) => (int) ($item['amount'] ?? 0));
        Invoice::query()->updateOrCreate([
            'provider' => 'stripe',
            'provider_invoice_id' => (string) ($invoice['id'] ?? ''),
        ], [
            'agency_id' => $agencyId,
            'subscription_id' => $subscription?->id,
            'number' => $invoice['number'] ?? null,
            'status' => (string) ($invoice['status'] ?? 'unknown'),
            'subtotal_minor' => (int) ($invoice['subtotal'] ?? 0),
            'tax_minor' => $tax,
            'total_minor' => (int) ($invoice['total'] ?? 0),
            'currency' => strtoupper((string) ($invoice['currency'] ?? 'USD')),
            'period_starts_at' => isset($invoice['period_start']) ? Carbon::createFromTimestampUTC((int) $invoice['period_start']) : null,
            'period_ends_at' => isset($invoice['period_end']) ? Carbon::createFromTimestampUTC((int) $invoice['period_end']) : null,
            'hosted_invoice_url' => $this->stripeUrl($invoice['hosted_invoice_url'] ?? null),
            'invoice_pdf_url' => $this->stripeUrl($invoice['invoice_pdf'] ?? null),
            'provider_updated_at' => $updatedAt,
        ]);
        if ($subscription && ! $subscription->provider_updated_at?->greaterThan($updatedAt)) {
            $billingStatus = match ((string) ($invoice['status'] ?? '')) {
                'paid' => 'paid',
                'open' => 'past_due',
                'uncollectible', 'void' => 'unpaid',
                default => $subscription->billing_status,
            };
            $subscription->update([
                'provider' => 'stripe',
                'provider_customer_id' => $invoice['customer'] ?? $subscription->provider_customer_id,
                'provider_subscription_id' => $invoice['subscription'] ?? $subscription->provider_subscription_id,
                'billing_status' => $billingStatus,
                'provider_updated_at' => $updatedAt,
            ]);
        }
    }

    /** @param array<string, mixed> $providerSubscription */
    private function subscription(string $agencyId, array $providerSubscription, int $eventTimestamp): void
    {
        $subscription = Subscription::query()->where('agency_id', $agencyId)->first();
        if (! $subscription) {
            return;
        }
        $updatedAt = Carbon::createFromTimestampUTC($eventTimestamp);
        if ($subscription->provider_updated_at?->greaterThan($updatedAt)) {
            return;
        }
        $providerStatus = (string) ($providerSubscription['status'] ?? 'incomplete');
        $billingStatus = match ($providerStatus) {
            'active' => 'paid',
            'trialing' => 'trialing',
            'past_due' => 'past_due',
            'paused' => 'paused',
            'canceled' => 'canceled',
            default => 'unpaid',
        };
        $subscription->update([
            'provider' => 'stripe',
            'provider_customer_id' => $providerSubscription['customer'] ?? $subscription->provider_customer_id,
            'provider_subscription_id' => $providerSubscription['id'] ?? $subscription->provider_subscription_id,
            'status' => in_array($providerStatus, ['active', 'trialing'], true) ? 'active' : 'inactive',
            'billing_status' => $billingStatus,
            'current_period_ends_at' => isset($providerSubscription['current_period_end'])
                ? Carbon::createFromTimestampUTC((int) $providerSubscription['current_period_end'])
                : $subscription->current_period_ends_at,
            'cancel_at' => isset($providerSubscription['cancel_at'])
                ? Carbon::createFromTimestampUTC((int) $providerSubscription['cancel_at'])
                : null,
            'canceled_at' => isset($providerSubscription['canceled_at'])
                ? Carbon::createFromTimestampUTC((int) $providerSubscription['canceled_at'])
                : null,
            'provider_updated_at' => $updatedAt,
        ]);
    }

    private function stripeUrl(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }
        $parts = parse_url($url);

        return ($parts['scheme'] ?? null) === 'https' &&
            isset($parts['host']) &&
            (str_ends_with($parts['host'], '.stripe.com') || str_ends_with($parts['host'], '.stripe.test'))
            ? $url
            : null;
    }
}
