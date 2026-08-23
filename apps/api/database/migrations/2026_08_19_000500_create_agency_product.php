<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_opening_hours', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agency_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->boolean('closed')->default(false);
            $table->timestamps();
            $table->unique(['agency_id', 'weekday']);
        });

        Schema::create('agency_closures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agency_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->boolean('closed')->default(true);
            $table->string('reason', 200)->nullable();
            $table->timestamps();
            $table->unique(['agency_id', 'date']);
        });

        Schema::create('newsletter_subscribers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agency_id')->constrained()->cascadeOnDelete();
            $table->string('email', 254);
            $table->string('status', 24)->default('active');
            $table->string('idempotency_key', 160);
            $table->char('payload_hash', 64);
            $table->char('unsubscribe_token_hash', 64)->unique();
            $table->string('consent_source', 80);
            $table->timestamp('consented_at');
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();
            $table->unique(['agency_id', 'email']);
            $table->unique(['agency_id', 'idempotency_key']);
        });

        Schema::create('newsletter_campaigns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('author_user_id')->constrained('users')->restrictOnDelete();
            $table->string('subject', 200);
            $table->text('body');
            $table->string('status', 24)->default('draft');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['agency_id', 'status', 'created_at']);
        });

        Schema::create('newsletter_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_id')->constrained('newsletter_campaigns')->cascadeOnDelete();
            $table->foreignUuid('subscriber_id')->constrained('newsletter_subscribers')->cascadeOnDelete();
            $table->string('event_type', 32);
            $table->string('adapter', 80);
            $table->string('error_code', 80)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['campaign_id', 'subscriber_id', 'event_type']);
        });

        Schema::create('analytics_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('listing_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 80);
            $table->char('anonymous_session_hash', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->index(['agency_id', 'type', 'occurred_at']);
            $table->unique(['agency_id', 'type', 'anonymous_session_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_events');
        Schema::dropIfExists('newsletter_events');
        Schema::dropIfExists('newsletter_campaigns');
        Schema::dropIfExists('newsletter_subscribers');
        Schema::dropIfExists('agency_closures');
        Schema::dropIfExists('agency_opening_hours');
    }
};
