<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\ApiException;
use App\Domain\Tenancy\AuditRecorder;
use App\Domain\Tenancy\FeatureResolver;
use App\Http\Controllers\Controller;
use App\Models\Agency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NewsletterSubscriptionController extends Controller
{
    public function store(Request $request, string $agency, FeatureResolver $features, AuditRecorder $audit): JsonResponse
    {
        $record = Agency::query()->where('status', 'active')->findOrFail($agency);
        $this->ensureEnabled($record, $features);
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'], 'consent' => ['required', 'accepted'],
            'consent_source' => ['required', 'string', 'max:80'],
        ]);
        $idempotencyKey = trim((string) $request->header('Idempotency-Key'));
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 160) {
            throw new ApiException('IDEMPOTENCY_KEY_REQUIRED', 'A valid Idempotency-Key header is required.');
        }
        $email = mb_strtolower($validated['email']);
        $payloadHash = hash('sha256', json_encode([$record->id, $email, $validated['consent_source']], JSON_THROW_ON_ERROR));
        $existingKey = DB::table('newsletter_subscribers')->where('agency_id', $record->id)->where('idempotency_key', $idempotencyKey)->first();
        if ($existingKey) {
            if (! hash_equals($existingKey->payload_hash, $payloadHash)) {
                throw new ApiException('IDEMPOTENCY_CONFLICT', 'This idempotency key was already used for another subscription.', 409);
            }

            return response()->json(['data' => $this->receipt($existingKey, $idempotencyKey)]);
        }

        $subscriber = DB::transaction(function () use ($request, $record, $email, $validated, $idempotencyKey, $payloadHash, $audit): object {
            $current = DB::table('newsletter_subscribers')->where('agency_id', $record->id)->where('email', $email)->first();
            $id = $current?->id ?? (string) Str::uuid();
            $token = $this->token($id, $idempotencyKey);
            $values = [
                'idempotency_key' => $idempotencyKey, 'payload_hash' => $payloadHash,
                'unsubscribe_token_hash' => hash('sha256', $token), 'consent_source' => $validated['consent_source'],
                'consented_at' => now(), 'unsubscribed_at' => null, 'status' => 'active', 'updated_at' => now(),
            ];
            if ($current) {
                DB::table('newsletter_subscribers')->where('id', $id)->update($values);
            } else {
                DB::table('newsletter_subscribers')->insert(array_merge($values, [
                    'id' => $id, 'agency_id' => $record->id, 'email' => $email, 'created_at' => now(),
                ]));
            }
            $audit->recordEntity($request, 'newsletter.subscriber_consented', 'newsletter_subscriber', $id, null, [
                'status' => 'active', 'consent_source' => $validated['consent_source'],
            ], $record->id);

            return DB::table('newsletter_subscribers')->where('id', $id)->first();
        });

        return response()->json(['data' => $this->receipt($subscriber, $idempotencyKey)], 201);
    }

    public function destroy(Request $request, string $token, AuditRecorder $audit): JsonResponse
    {
        $subscriber = DB::table('newsletter_subscribers')->where('unsubscribe_token_hash', hash('sha256', $token))->firstOrFail();
        if ($subscriber->status !== 'unsubscribed') {
            DB::transaction(function () use ($request, $subscriber, $audit): void {
                DB::table('newsletter_subscribers')->where('id', $subscriber->id)->update([
                    'status' => 'unsubscribed', 'unsubscribed_at' => now(), 'updated_at' => now(),
                ]);
                $audit->recordEntity($request, 'newsletter.subscriber_unsubscribed', 'newsletter_subscriber', $subscriber->id, [
                    'status' => $subscriber->status,
                ], ['status' => 'unsubscribed'], $subscriber->agency_id);
            });
        }

        return response()->json(status: 204);
    }

    private function ensureEnabled(Agency $agency, FeatureResolver $features): void
    {
        if (! $features->resolve('newsletters', $agency)['enabled']) {
            throw new ApiException('FEATURE_DISABLED', 'Newsletters are not enabled for this agency.', 403);
        }
    }

    private function token(string $subscriberId, string $idempotencyKey): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $subscriberId.'|'.$idempotencyKey, (string) config('app.key'), true)), '+/', '-_'), '=');
    }

    /** @return array<string, mixed> */
    private function receipt(object $subscriber, string $idempotencyKey): array
    {
        return ['status' => $subscriber->status, 'unsubscribe_token' => $this->token($subscriber->id, $idempotencyKey)];
    }
}
