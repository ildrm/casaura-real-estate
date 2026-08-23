<?php

namespace App\Console\Commands;

use App\Models\DataSubjectRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class ProcessPrivacyDeletion extends Command
{
    protected $signature = 'privacy:process-deletion {request} {--approval-reference=}';

    protected $description = 'Anonymize an approved data subject after operator identity and legal review';

    public function handle(): int
    {
        $reference = trim((string) $this->option('approval-reference'));
        if (mb_strlen($reference) < 4 || mb_strlen($reference) > 160) {
            $this->error('A 4–160 character external approval reference is required.');

            return self::FAILURE;
        }

        $request = DataSubjectRequest::query()->find((string) $this->argument('request'));
        if (! $request || $request->type !== 'deletion' || $request->status !== 'pending_operator_review') {
            $this->error('The deletion request is not pending operator review.');

            return self::FAILURE;
        }
        if (DB::table('agencies')->where('owner_user_id', $request->subject_user_id)->exists()) {
            $this->error('Ownership must be reassigned or the agency closed before this account can be anonymized.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($request, $reference): void {
            $request = DataSubjectRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $user = DB::table('users')->where('id', $request->subject_user_id)->lockForUpdate()->firstOrFail();
            $membershipIds = DB::table('agency_members')->where('user_id', $user->id)->pluck('id');

            DB::table('member_roles')->whereIn('agency_member_id', $membershipIds)->delete();
            DB::table('agency_members')->where('user_id', $user->id)->update([
                'status' => 'inactive',
                'job_title' => null,
                'invitation_token_hash' => null,
                'invitation_expires_at' => null,
                'invitation_cancelled_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('favorites')->where('user_id', $user->id)->delete();
            DB::table('property_reactions')->where('user_id', $user->id)->delete();
            DB::table('notifications')->where('user_id', $user->id)->delete();
            DB::table('reminders')->where('assigned_user_id', $user->id)->delete();
            DB::table('conversation_participants')->where('user_id', $user->id)->delete();
            DB::table('messages')->where('sender_user_id', $user->id)->update(['body' => '[removed by privacy request]']);
            DB::table('viewing_requests')->where('consumer_user_id', $user->id)->update(['notes' => null]);
            DB::table('leads')->where('consumer_user_id', $user->id)->orWhere('email', mb_strtolower($user->email))->update([
                'name' => 'Deleted user',
                'email' => DB::raw("'deleted+' || id || '@invalid.casaura'"),
                'phone' => null,
                'message' => '[removed by privacy request]',
                'updated_at' => now(),
            ]);
            DB::table('newsletter_subscribers')->where('email', mb_strtolower($user->email))->get()->each(function ($subscriber): void {
                DB::table('newsletter_subscribers')->where('id', $subscriber->id)->update([
                    'email' => "deleted+{$subscriber->id}@invalid.casaura",
                    'status' => 'unsubscribed',
                    'unsubscribed_at' => now(),
                    'updated_at' => now(),
                ]);
            });
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            DB::table('consent_records')->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
            DB::table('users')->where('id', $user->id)->update([
                'name' => 'Deleted user',
                'email' => "deleted+{$user->id}@invalid.casaura",
                'password' => Hash::make(Str::random(64)),
                'email_verified_at' => null,
                'status' => 'suspended',
                'suspended_at' => now(),
                'remember_token' => null,
                'security_version' => ((int) $user->security_version) + 1,
                'mfa_secret' => null,
                'mfa_confirmed_at' => null,
                'mfa_last_used_timestep' => null,
                'mfa_recovery_codes' => null,
                'updated_at' => now(),
            ]);
            $request->update([
                'status' => 'completed',
                'operator_reference' => $reference,
                'started_at' => now(),
                'completed_at' => now(),
            ]);
        });

        $this->info('The data subject was anonymized and active credentials were revoked.');

        return self::SUCCESS;
    }
}
