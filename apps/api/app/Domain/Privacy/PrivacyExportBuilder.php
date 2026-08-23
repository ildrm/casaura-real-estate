<?php

namespace App\Domain\Privacy;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class PrivacyExportBuilder
{
    /** @return array<string, mixed> */
    public function build(User $user): array
    {
        $id = $user->id;
        $conversationIds = DB::table('conversation_participants')->where('user_id', $id)->pluck('conversation_id');

        return [
            'format' => 'casaura-data-export-v1',
            'generated_at' => now()->toIso8601String(),
            'account' => $user->only(['id', 'name', 'email', 'locale', 'timezone', 'status', 'email_verified_at', 'created_at', 'updated_at']),
            'memberships' => DB::table('agency_members')
                ->join('agencies', 'agencies.id', '=', 'agency_members.agency_id')
                ->where('agency_members.user_id', $id)
                ->get(['agency_members.id', 'agency_members.agency_id', 'agencies.name as agency_name', 'agency_members.status', 'agency_members.job_title', 'agency_members.accepted_at', 'agency_members.created_at']),
            'consents' => DB::table('consent_records')->where('user_id', $id)
                ->get(['purpose', 'document_version', 'source', 'consented_at', 'revoked_at']),
            'favorites' => DB::table('favorites')->where('user_id', $id)->get(['listing_id', 'created_at']),
            'reactions' => DB::table('property_reactions')->where('user_id', $id)->get(['listing_id', 'reaction', 'created_at', 'updated_at']),
            'leads' => DB::table('leads')->where('consumer_user_id', $id)
                ->get(['id', 'agency_id', 'listing_id', 'status', 'priority', 'name', 'email', 'phone', 'message', 'consent_version', 'consent_text', 'consented_at', 'created_at', 'updated_at']),
            'conversations' => DB::table('conversations')->whereIn('id', $conversationIds)
                ->get(['id', 'agency_id', 'lead_id', 'listing_id', 'subject', 'last_message_at', 'created_at']),
            'messages' => DB::table('messages')->whereIn('conversation_id', $conversationIds)
                ->get(['id', 'conversation_id', 'sender_user_id', 'body', 'created_at']),
            'viewings' => DB::table('viewing_requests')->where('consumer_user_id', $id)
                ->get(['id', 'agency_id', 'lead_id', 'listing_id', 'status', 'starts_at', 'ends_at', 'timezone', 'notes', 'created_at', 'updated_at']),
            'notifications' => DB::table('notifications')->where('user_id', $id)
                ->get(['id', 'agency_id', 'type', 'title', 'body', 'data', 'read_at', 'created_at']),
            'security_events' => DB::table('audit_logs')->where('actor_user_id', $id)
                ->get(['id', 'agency_id', 'action', 'entity_type', 'entity_id', 'request_id', 'created_at']),
        ];
    }
}
