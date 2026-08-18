<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agencies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->string('name', 160);
            $table->string('slug', 180)->unique();
            $table->string('legal_name', 180)->nullable();
            $table->string('registration_number', 100)->nullable();
            $table->string('license_number', 100)->nullable();
            $table->string('email', 254);
            $table->timestamp('email_verified_at')->nullable();
            $table->string('phone', 40)->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->string('website', 500)->nullable();
            $table->text('short_description')->nullable();
            $table->longText('description')->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->string('verification_status', 24)->default('unverified')->index();
            $table->string('status', 24)->default('active')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('agency_branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('agency_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('slug', 180);
            $table->boolean('is_primary')->default(false);
            $table->string('email', 254)->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('formatted_address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->timestamps();
            $table->unique(['agency_id', 'slug']);
            $table->index(['agency_id', 'is_primary']);
        });

        Schema::create('agency_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 24)->default('invited')->index();
            $table->string('job_title', 120)->nullable();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->unique(['agency_id', 'user_id']);
            $table->index(['agency_id', 'status']);
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('agency_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('scope', 64)->default('platform');
            $table->string('name', 120);
            $table->string('slug', 120);
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->unique(['scope', 'slug']);
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 160)->unique();
            $table->string('group', 80)->index();
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignUuid('role_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('member_roles', function (Blueprint $table) {
            $table->foreignUuid('agency_member_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['agency_member_id', 'role_id']);
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('slug', 120)->unique();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(false);
            $table->unsignedBigInteger('price_amount_minor')->default(0);
            $table->char('price_currency', 3)->default('USD');
            $table->string('billing_interval', 24)->default('month');
            $table->timestamps();
        });

        Schema::create('plan_entitlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->constrained()->cascadeOnDelete();
            $table->string('key', 160);
            $table->json('value');
            $table->unsignedBigInteger('quota')->nullable();
            $table->string('reset_period', 24)->nullable();
            $table->timestamps();
            $table->unique(['plan_id', 'key']);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('plan_id')->constrained()->restrictOnDelete();
            $table->string('status', 24)->default('active')->index();
            $table->string('billing_status', 24)->default('not_required');
            $table->timestamp('promotional_starts_at')->nullable();
            $table->timestamp('promotional_ends_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('free_until')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->timestamps();
            $table->unique('agency_id');
        });

        Schema::create('feature_flags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 160)->unique();
            $table->string('description', 255)->nullable();
            $table->boolean('default_enabled')->default(false);
            $table->json('environment_rules')->nullable();
            $table->timestamps();
        });

        Schema::create('feature_flag_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('feature_flag_id')->constrained()->cascadeOnDelete();
            $table->string('scope_type', 32);
            $table->uuid('scope_id');
            $table->boolean('enabled');
            $table->json('value')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->unique(['feature_flag_id', 'scope_type', 'scope_id'], 'feature_flag_scope_unique');
            $table->index(['scope_type', 'scope_id']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('namespace', 100);
            $table->string('key', 160);
            $table->json('value');
            $table->boolean('is_secret')->default(false);
            $table->timestamps();
            $table->unique(['namespace', 'key']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('agency_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 160)->index();
            $table->string('entity_type', 160)->nullable();
            $table->uuid('entity_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->uuid('request_id')->nullable()->index();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['agency_id', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuidMorphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('feature_flag_overrides');
        Schema::dropIfExists('feature_flags');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plan_entitlements');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('member_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('agency_members');
        Schema::dropIfExists('agency_branches');
        Schema::dropIfExists('agencies');
    }
};
