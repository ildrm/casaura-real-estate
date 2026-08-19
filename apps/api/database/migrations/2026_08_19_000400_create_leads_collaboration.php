<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('consumer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('assigned_member_id')->nullable()->constrained('agency_members')->nullOnDelete();
            $table->string('idempotency_key', 160);
            $table->char('payload_hash', 64);
            $table->string('name', 160);
            $table->string('email', 254);
            $table->string('phone', 40)->nullable();
            $table->text('message');
            $table->string('source', 40)->default('property_detail');
            $table->string('status', 24)->default('new');
            $table->string('priority', 16)->default('normal');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('response_due_at')->nullable();
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
            $table->unique(['agency_id', 'idempotency_key']);
            $table->index(['agency_id', 'status', 'created_at']);
            $table->index(['consumer_user_id', 'created_at']);
        });

        Schema::create('lead_status_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('from_assigned_member_id')->nullable()->constrained('agency_members')->nullOnDelete();
            $table->foreignUuid('to_assigned_member_id')->nullable()->constrained('agency_members')->nullOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['lead_id', 'created_at']);
        });

        Schema::create('conversations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('lead_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('listing_id')->constrained()->cascadeOnDelete();
            $table->string('subject', 200);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->index(['agency_id', 'last_message_at']);
        });

        Schema::create('conversation_participants', function (Blueprint $table): void {
            $table->foreignUuid('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 24);
            $table->timestamp('last_read_at')->nullable();
            $table->primary(['conversation_id', 'user_id']);
        });

        Schema::create('messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['conversation_id', 'created_at', 'id']);
        });

        Schema::create('viewing_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('consumer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('assigned_member_id')->nullable()->constrained('agency_members')->nullOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('timezone', 64);
            $table->string('status', 24)->default('requested');
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['agency_id', 'starts_at']);
            $table->index(['consumer_user_id', 'starts_at']);
        });

        Schema::create('viewing_status_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('viewing_request_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('reminders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('assigned_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('lead_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('viewing_request_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->timestamp('due_at');
            $table->string('status', 24)->default('pending');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'due_at']);
        });

        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('agency_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 80);
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->json('data')->nullable();
            $table->string('deduplication_key', 200)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['user_id', 'deduplication_key']);
            $table->index(['user_id', 'read_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('reminders');
        Schema::dropIfExists('viewing_status_history');
        Schema::dropIfExists('viewing_requests');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversation_participants');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('lead_status_history');
        Schema::dropIfExists('leads');
    }
};
