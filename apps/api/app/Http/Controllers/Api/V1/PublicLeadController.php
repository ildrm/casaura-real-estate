<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Analytics\AnalyticsRecorder;
use App\Domain\ApiException;
use App\Domain\Notifications\NotificationDispatcher;
use App\Domain\Tenancy\AuditRecorder;
use App\Http\Controllers\Controller;
use App\Models\SearchDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PublicLeadController extends Controller
{
    public function store(
        Request $request,
        string $listing,
        NotificationDispatcher $notifications,
        AuditRecorder $audit,
        AnalyticsRecorder $analytics,
    ): JsonResponse {
        $document = SearchDocument::query()->where('status', 'published')->findOrFail($listing);
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'string', 'max:40'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'consent' => ['required', 'accepted'],
        ]);
        $idempotencyKey = trim((string) $request->header('Idempotency-Key'));
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 160) {
            throw new ApiException('IDEMPOTENCY_KEY_REQUIRED', 'A valid Idempotency-Key header is required.');
        }
        $payloadHash = hash('sha256', json_encode([$listing, $validated], JSON_THROW_ON_ERROR));
        $existing = DB::table('leads')->where('agency_id', $document->agency_id)->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            if (! hash_equals($existing->payload_hash, $payloadHash)) {
                throw new ApiException('IDEMPOTENCY_CONFLICT', 'This idempotency key was already used for another inquiry.', 409);
            }

            return response()->json(['data' => $this->projection($existing)], 200);
        }

        [$lead, $created] = DB::transaction(function () use ($request, $validated, $document, $listing, $idempotencyKey, $payloadHash, $notifications, $audit, $analytics): array {
            $now = now();
            $leadId = (string) Str::uuid();
            $conversationId = (string) Str::uuid();
            $inserted = DB::table('leads')->insertOrIgnore([
                'id' => $leadId, 'agency_id' => $document->agency_id, 'listing_id' => $listing,
                'consumer_user_id' => $request->user()?->id, 'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash, 'name' => $validated['name'],
                'email' => mb_strtolower($validated['email']), 'phone' => $validated['phone'] ?? null,
                'message' => $validated['message'],
                'consent_version' => config('privacy.inquiry_consent_version'),
                'consent_text' => config('privacy.inquiry_consent_text'),
                'consent_text_sha256' => hash('sha256', (string) config('privacy.inquiry_consent_text')),
                'consented_at' => $now,
                'status' => 'new', 'priority' => 'normal',
                'version' => 1, 'response_due_at' => $now->copy()->addHours(4),
                'last_activity_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($inserted !== 1) {
                $existing = DB::table('leads')->where('agency_id', $document->agency_id)
                    ->where('idempotency_key', $idempotencyKey)->firstOrFail();
                if (! hash_equals($existing->payload_hash, $payloadHash)) {
                    throw new ApiException('IDEMPOTENCY_CONFLICT', 'This idempotency key was already used for another inquiry.', 409);
                }

                return [$existing, false];
            }
            DB::table('lead_status_history')->insert([
                'id' => (string) Str::uuid(), 'lead_id' => $leadId, 'actor_user_id' => $request->user()?->id,
                'from_status' => null, 'to_status' => 'new', 'created_at' => $now,
            ]);
            DB::table('conversations')->insert([
                'id' => $conversationId, 'agency_id' => $document->agency_id, 'lead_id' => $leadId,
                'listing_id' => $listing, 'subject' => 'Property inquiry', 'last_message_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($request->user()) {
                DB::table('conversation_participants')->insert([
                    'conversation_id' => $conversationId, 'user_id' => $request->user()->id, 'role' => 'consumer',
                ]);
            }
            $ownerId = DB::table('agencies')->where('id', $document->agency_id)->value('owner_user_id');
            DB::table('conversation_participants')->insertOrIgnore([
                'conversation_id' => $conversationId, 'user_id' => $ownerId, 'role' => 'agency',
            ]);
            DB::table('messages')->insert([
                'id' => (string) Str::uuid7(), 'conversation_id' => $conversationId,
                'sender_user_id' => $request->user()?->id, 'body' => $validated['message'], 'created_at' => $now,
            ]);
            $notifications->dispatch($ownerId, $document->agency_id, 'lead.created', 'New property inquiry', null, [
                'lead_id' => $leadId, 'listing_id' => $listing,
            ], 'lead-created:'.$leadId);
            $audit->recordEntity($request, 'lead.created', 'lead', $leadId, null, [
                'status' => 'new', 'listing_id' => $listing,
            ], $document->agency_id);
            $analytics->recordOutcome($document->agency_id, 'lead.created', $listing, ['status' => 'new']);

            return [DB::table('leads')->where('id', $leadId)->first(), true];
        });

        return response()->json(['data' => $this->projection($lead)], $created ? 201 : 200);
    }

    /** @return array<string, mixed> */
    private function projection(object $lead): array
    {
        return [
            'id' => $lead->id,
            'listing_id' => $lead->listing_id,
            'status' => $lead->status,
            'priority' => $lead->priority,
            'contact' => ['name' => $lead->name, 'email' => $lead->email, 'phone' => $lead->phone],
            'assigned_member_id' => $lead->assigned_member_id,
            'first_responded_at' => $lead->first_responded_at,
            'version' => (int) $lead->version,
            'conversation_id' => DB::table('conversations')->where('lead_id', $lead->id)->value('id'),
            'created_at' => $lead->created_at,
        ];
    }
}
