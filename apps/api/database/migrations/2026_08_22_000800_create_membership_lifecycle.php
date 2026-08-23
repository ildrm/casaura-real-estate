<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_members', function (Blueprint $table): void {
            $table->foreignUuid('invited_by_user_id')->nullable()->after('job_title')->constrained('users')->nullOnDelete();
            $table->char('invitation_token_hash', 64)->nullable()->unique()->after('invited_by_user_id');
            $table->timestamp('invitation_expires_at')->nullable()->after('invitation_token_hash');
            $table->timestamp('invitation_cancelled_at')->nullable()->after('invitation_expires_at');
            $table->boolean('invited_user_was_created')->default(false)->after('invitation_cancelled_at');
            $table->boolean('is_public')->default(false)->after('invited_user_was_created');
            $table->unsignedInteger('public_position')->nullable()->after('is_public');
            $table->index(['agency_id', 'is_public', 'public_position']);
        });
    }

    public function down(): void
    {
        Schema::table('agency_members', function (Blueprint $table): void {
            $table->dropIndex(['agency_id', 'is_public', 'public_position']);
            $table->dropForeign(['invited_by_user_id']);
            $table->dropUnique(['invitation_token_hash']);
            $table->dropColumn([
                'invited_by_user_id',
                'invitation_token_hash',
                'invitation_expires_at',
                'invitation_cancelled_at',
                'invited_user_was_created',
                'is_public',
                'public_position',
            ]);
        });
    }
};
