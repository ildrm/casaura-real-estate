<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1);
        });

        Schema::create('abuse_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('reporter_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('listing_id')->constrained()->restrictOnDelete();
            $table->string('idempotency_key', 160);
            $table->char('payload_hash', 64);
            $table->string('category', 40);
            $table->text('details')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['reporter_user_id', 'idempotency_key']);
        });

        Schema::create('moderation_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('abuse_report_id')->unique()->constrained('abuse_reports')->restrictOnDelete();
            $table->string('target_type', 40)->default('listing');
            $table->uuid('target_id');
            $table->string('category', 40);
            $table->string('status', 24)->default('open');
            $table->foreignUuid('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('outcome', 120)->nullable();
            $table->text('note')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index(['target_type', 'target_id']);
        });

        Schema::create('moderation_case_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('moderation_case_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->string('outcome', 120)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['moderation_case_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moderation_case_history');
        Schema::dropIfExists('moderation_cases');
        Schema::dropIfExists('abuse_reports');
        Schema::table('settings', fn (Blueprint $table) => $table->dropColumn('version'));
    }
};
