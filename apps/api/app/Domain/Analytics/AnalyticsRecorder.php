<?php

namespace App\Domain\Analytics;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AnalyticsRecorder
{
    /** @param array<string, scalar|null> $metadata */
    public function recordOutcome(
        string $agencyId,
        string $type,
        ?string $listingId,
        array $metadata,
    ): void {
        DB::table('analytics_events')->insert([
            'id' => (string) Str::uuid(),
            'agency_id' => $agencyId,
            'listing_id' => $listingId,
            'type' => $type,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
        ]);
    }

    /** @param array<string, scalar|null> $metadata */
    public function recordPublicView(
        Request $request,
        string $agencyId,
        string $type,
        ?string $listingId,
        array $metadata,
    ): void {
        $fingerprint = implode('|', [
            $request->ip() ?? 'unknown',
            mb_substr((string) $request->userAgent(), 0, 512),
            now()->format('Y-m-d-H'),
            $agencyId,
            $type,
            $listingId ?? 'storefront',
        ]);

        DB::table('analytics_events')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'agency_id' => $agencyId,
            'listing_id' => $listingId,
            'type' => $type,
            'anonymous_session_hash' => hash_hmac('sha256', $fingerprint, (string) config('app.key')),
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
        ]);
    }
}
