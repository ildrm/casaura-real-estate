<?php

namespace App\Console\Commands;

use App\Models\DataSubjectRequest;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class EnforcePrivacyRetention extends Command
{
    protected $signature = 'privacy:enforce-retention {--dry-run}';

    protected $description = 'Apply privacy retention windows and purge expired encrypted exports';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $pseudonymizeBefore = now()->subDays((int) config('privacy.analytics_pseudonymize_days'));
        $deleteBefore = now()->subDays((int) config('privacy.analytics_delete_days'));
        $analyticsToPseudonymize = DB::table('analytics_events')
            ->whereNotNull('anonymous_session_hash')->where('occurred_at', '<=', $pseudonymizeBefore)->count();
        $analyticsToDelete = DB::table('analytics_events')->where('occurred_at', '<=', $deleteBefore)->count();
        $expiredAiGenerations = DB::table('ai_generations')
            ->whereNotNull('content_expires_at')->where('content_expires_at', '<=', now())->count();
        $expiredAiSessions = DB::table('ai_sessions')
            ->whereNotNull('content_expires_at')->where('content_expires_at', '<=', now())->pluck('id');
        $providerPayloadCutoff = now()->subDays((int) config('integrations.raw_payload_retention_days', 30));
        $providerPayloads = DB::table('data_source_records')
            ->whereNotNull('raw_envelope')->where('created_at', '<=', $providerPayloadCutoff)->count();
        $expiredExports = DataSubjectRequest::query()
            ->where('type', 'export')->where('status', 'completed')->where('expires_at', '<=', now())->get();
        $orphanCutoff = now()->subDays((int) config('privacy.orphan_invitation_days'));
        $orphanUsers = User::query()
            ->whereNull('email_verified_at')
            ->where('created_at', '<=', $orphanCutoff)
            ->whereDoesntHave('memberships', fn ($query) => $query->whereNotNull('accepted_at')->where('status', 'active'))
            ->whereDoesntHave('ownedAgencies')
            ->whereHas('memberships', fn ($query) => $query->whereNull('accepted_at')->where('status', 'inactive'))
            ->get();

        $this->line("Analytics identifiers to pseudonymize: {$analyticsToPseudonymize}");
        $this->line("Raw analytics events to delete: {$analyticsToDelete}");
        $this->line("Expired AI generation contents to redact: {$expiredAiGenerations}");
        $this->line('Expired AI sessions to purge: '.$expiredAiSessions->count());
        $this->line("Expired provider raw payloads to redact: {$providerPayloads}");
        $this->line('Expired exports to purge: '.$expiredExports->count());
        $this->line('Expired invitation-only accounts to remove: '.$orphanUsers->count());
        if ($dryRun) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($pseudonymizeBefore, $deleteBefore, $orphanUsers, $expiredAiSessions, $providerPayloadCutoff): void {
            DB::table('analytics_events')->where('occurred_at', '<=', $deleteBefore)->delete();
            DB::table('promotion_impressions')->where('occurred_at', '<=', $deleteBefore)->delete();
            DB::table('analytics_events')->whereNotNull('anonymous_session_hash')
                ->where('occurred_at', '<=', $pseudonymizeBefore)->update(['anonymous_session_hash' => null]);
            foreach ($orphanUsers as $user) {
                $user->delete();
            }
            DB::table('ai_messages')->whereIn('ai_session_id', $expiredAiSessions)->delete();
            DB::table('ai_generations')
                ->whereNotNull('content_expires_at')->where('content_expires_at', '<=', now())
                ->update(['output' => null, 'parsed_filters' => null]);
            DB::table('ai_sessions')->whereIn('id', $expiredAiSessions)->delete();
            DB::table('data_source_records')->whereNotNull('raw_envelope')
                ->where('created_at', '<=', $providerPayloadCutoff)->update(['raw_envelope' => null]);
        });

        foreach ($expiredExports as $export) {
            if ($export->output_storage_key) {
                Storage::disk('privacy_exports')->delete($export->output_storage_key);
            }
            $export->update([
                'status' => 'expired',
                'output_storage_key' => null,
                'output_checksum_sha256' => null,
            ]);
        }

        $this->info('Privacy retention enforcement completed.');

        return self::SUCCESS;
    }
}
