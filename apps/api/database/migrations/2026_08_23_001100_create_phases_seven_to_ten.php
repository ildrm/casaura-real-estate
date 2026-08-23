<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('real_estate_data_providers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key', 80)->unique();
            $table->string('name', 160);
            $table->string('adapter', 120);
            $table->string('protocol', 80);
            $table->boolean('is_active')->default(true);
            $table->json('capabilities')->nullable();
            $table->timestamps();
        });

        Schema::create('provider_connections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('provider_id')->constrained('real_estate_data_providers')->restrictOnDelete();
            $table->string('name', 160);
            $table->string('base_url', 500);
            $table->string('token_url', 500);
            $table->string('client_id', 255);
            $table->string('secret_reference', 255);
            $table->json('resources');
            $table->json('rights_snapshot');
            $table->string('data_dictionary_version', 32)->default('2.0');
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->string('last_sync_status', 32)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['agency_id', 'name']);
            $table->index(['agency_id', 'is_enabled']);
        });

        Schema::create('field_mappings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_connection_id')->constrained()->cascadeOnDelete();
            $table->string('resource', 120);
            $table->unsignedInteger('version');
            $table->json('fields');
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->unique(['provider_connection_id', 'resource', 'version'], 'field_mapping_version_unique');
        });

        Schema::create('sync_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_connection_id')->constrained()->cascadeOnDelete();
            $table->string('mode', 24);
            $table->string('status', 32)->index();
            $table->string('idempotency_key', 255);
            $table->char('payload_hash', 64);
            $table->text('start_cursor')->nullable();
            $table->text('end_cursor')->nullable();
            $table->unsignedInteger('records_fetched')->default(0);
            $table->unsignedInteger('records_imported')->default(0);
            $table->unsignedInteger('records_skipped')->default(0);
            $table->unsignedInteger('records_failed')->default(0);
            $table->string('failure_code', 120)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['provider_connection_id', 'idempotency_key'], 'sync_connection_key_unique');
        });

        Schema::create('data_source_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sync_job_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('property_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('listing_id')->nullable()->constrained()->nullOnDelete();
            $table->string('resource', 120);
            $table->string('external_record_id', 255);
            $table->char('payload_hash', 64);
            $table->unsignedInteger('mapping_version');
            $table->json('rights_snapshot');
            $table->timestamp('provider_modified_at')->nullable();
            $table->string('outcome', 32);
            $table->json('raw_envelope')->nullable();
            $table->timestamps();
            $table->unique(
                ['provider_connection_id', 'resource', 'external_record_id', 'payload_hash'],
                'source_record_identity_unique',
            );
            $table->index(['provider_connection_id', 'resource', 'external_record_id'], 'source_record_lookup');
        });

        Schema::create('import_errors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('sync_job_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('data_source_record_id')->nullable()->constrained()->nullOnDelete();
            $table->string('field', 160)->nullable();
            $table->string('code', 120);
            $table->boolean('retryable')->default(false);
            $table->text('detail')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['sync_job_id', 'resolved_at']);
        });

        Schema::create('duplicate_candidates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('left_property_id')->nullable()->constrained('properties')->nullOnDelete();
            $table->foreignUuid('right_property_id')->nullable()->constrained('properties')->nullOnDelete();
            $table->foreignUuid('data_source_record_id')->nullable()->constrained('data_source_records')->nullOnDelete();
            $table->decimal('score', 5, 4);
            $table->json('reasons');
            $table->string('status', 32)->default('pending');
            $table->foreignUuid('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('merge_snapshot')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['agency_id', 'status', 'created_at']);
        });

        Schema::create('collections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 120);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['owner_user_id', 'updated_at']);
        });

        Schema::create('collection_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('collection_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16);
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['collection_id', 'user_id']);
        });

        Schema::create('collection_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('collection_id')->constrained()->cascadeOnDelete();
            $table->char('invited_email_hash', 64);
            $table->string('role', 16);
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['collection_id', 'expires_at']);
        });

        Schema::create('collection_properties', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('collection_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('added_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('position');
            $table->timestamps();
            $table->unique(['collection_id', 'listing_id']);
            $table->unique(['collection_id', 'position']);
        });

        Schema::create('comparison_histories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->json('listing_ids');
            $table->char('fingerprint', 64);
            $table->timestamps();
            $table->unique(['user_id', 'fingerprint']);
        });

        Schema::create('market_aggregate_cache', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->char('cache_key', 64)->unique();
            $table->unsignedInteger('cohort_size');
            $table->json('aggregate');
            $table->timestamp('calculated_at');
            $table->timestamp('expires_at');
        });

        Schema::create('ai_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('purpose', 32);
            $table->string('status', 24)->default('active');
            $table->timestamp('content_expires_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('ai_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('ai_session_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16);
            $table->text('content')->nullable();
            $table->char('content_hash', 64);
            $table->timestamps();
        });

        Schema::create('ai_generations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('ai_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('agency_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('listing_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('adapter', 80);
            $table->string('model', 120);
            $table->string('purpose', 32);
            $table->string('status', 24);
            $table->char('prompt_hash', 64);
            $table->json('parsed_filters')->nullable();
            $table->json('output')->nullable();
            $table->unsignedInteger('latency_ms')->default(0);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->string('safety_code', 120)->nullable();
            $table->timestamp('content_expires_at')->nullable();
            $table->timestamps();
            $table->index(['agency_id', 'purpose', 'created_at']);
        });

        Schema::create('ai_citations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('ai_generation_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 32);
            $table->uuid('source_id');
            $table->json('field_paths');
            $table->char('snapshot_hash', 64);
            $table->timestamps();
        });

        Schema::create('ai_listing_suggestions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('ai_generation_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('source_listing_version');
            $table->json('suggested_fields');
            $table->json('applied_fields')->nullable();
            $table->foreignUuid('applied_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->index(['agency_id', 'listing_id', 'created_at']);
        });

        Schema::create('ai_safety_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('ai_generation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 80);
            $table->string('action', 32);
            $table->string('rule_version', 32);
            $table->timestamp('created_at');
            $table->index(['category', 'created_at']);
        });

        Schema::table('plans', function (Blueprint $table): void {
            $table->string('provider_price_id', 255)->nullable()->unique();
        });
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->string('provider', 32)->nullable();
            $table->string('provider_customer_id', 255)->nullable()->index();
            $table->string('provider_subscription_id', 255)->nullable()->unique();
            $table->timestamp('provider_updated_at')->nullable();
            $table->timestamp('cancel_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
        });

        Schema::create('billing_customers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agency_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('provider_customer_id', 255);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['agency_id', 'provider']);
            $table->unique(['provider', 'provider_customer_id']);
        });

        Schema::create('billing_checkout_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('plan_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 255);
            $table->char('payload_hash', 64);
            $table->string('provider_session_id', 255);
            $table->string('status', 24);
            $table->text('url');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['agency_id', 'idempotency_key']);
            $table->unique('provider_session_id');
        });

        Schema::create('billing_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider', 32);
            $table->string('provider_event_id', 255);
            $table->string('event_type', 160);
            $table->timestamp('provider_created_at');
            $table->char('payload_hash', 64);
            $table->string('provider_object_id', 255)->nullable()->index();
            $table->string('provider_customer_id', 255)->nullable()->index();
            $table->json('summary')->nullable();
            $table->foreignUuid('agency_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 24);
            $table->string('failure_code', 120)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_event_id']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 32);
            $table->string('provider_invoice_id', 255);
            $table->string('number', 120)->nullable();
            $table->string('status', 32);
            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->char('currency', 3);
            $table->timestamp('period_starts_at')->nullable();
            $table->timestamp('period_ends_at')->nullable();
            $table->text('hosted_invoice_url')->nullable();
            $table->text('invoice_pdf_url')->nullable();
            $table->timestamp('provider_updated_at');
            $table->timestamps();
            $table->unique(['provider', 'provider_invoice_id']);
            $table->index(['agency_id', 'provider_updated_at']);
        });

        Schema::create('promotion_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('family_id');
            $table->unsignedInteger('version');
            $table->string('name', 160);
            $table->string('placement', 80);
            $table->string('label', 80);
            $table->string('disclosure', 255);
            $table->json('eligible_plan_ids');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedInteger('max_impressions');
            $table->string('status', 24)->default('active');
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['family_id', 'version']);
            $table->index(['placement', 'status', 'starts_at', 'ends_at']);
        });

        Schema::create('promotion_campaigns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('promotion_policy_id')->constrained()->restrictOnDelete();
            $table->string('placement', 80);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedInteger('impression_cap');
            $table->unsignedInteger('impression_count')->default(0);
            $table->string('status', 24)->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['placement', 'status', 'starts_at', 'ends_at']);
            $table->index(['agency_id', 'updated_at']);
        });

        Schema::create('promotion_impressions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('promotion_campaign_id')->constrained()->cascadeOnDelete();
            $table->char('anonymous_dedupe_hash', 64);
            $table->string('placement', 80);
            $table->timestamp('occurred_at');
            $table->unique(['promotion_campaign_id', 'anonymous_dedupe_hash', 'placement'], 'promotion_impression_unique');
            $table->index(['occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_impressions');
        Schema::dropIfExists('promotion_campaigns');
        Schema::dropIfExists('promotion_policies');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('billing_events');
        Schema::dropIfExists('billing_checkout_sessions');
        Schema::dropIfExists('billing_customers');
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn([
                'provider', 'provider_customer_id', 'provider_subscription_id',
                'provider_updated_at', 'cancel_at', 'canceled_at',
            ]);
        });
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn('provider_price_id');
        });
        Schema::dropIfExists('ai_safety_events');
        Schema::dropIfExists('ai_listing_suggestions');
        Schema::dropIfExists('ai_citations');
        Schema::dropIfExists('ai_generations');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_sessions');
        Schema::dropIfExists('market_aggregate_cache');
        Schema::dropIfExists('comparison_histories');
        Schema::dropIfExists('collection_properties');
        Schema::dropIfExists('collection_invitations');
        Schema::dropIfExists('collection_members');
        Schema::dropIfExists('collections');
        Schema::dropIfExists('duplicate_candidates');
        Schema::dropIfExists('import_errors');
        Schema::dropIfExists('data_source_records');
        Schema::dropIfExists('sync_jobs');
        Schema::dropIfExists('field_mappings');
        Schema::dropIfExists('provider_connections');
        Schema::dropIfExists('real_estate_data_providers');
    }
};
