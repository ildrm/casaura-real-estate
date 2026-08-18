<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 80)->unique();
            $table->string('name', 120);
            $table->string('category', 80)->index();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('amenities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 80)->unique();
            $table->string('name', 120);
            $table->string('group', 80)->index();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('property_feature_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 80)->unique();
            $table->string('name', 120);
            $table->string('value_type', 24);
            $table->string('unit', 40)->nullable();
            $table->json('validation')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('addresses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('agency_id')->constrained()->cascadeOnDelete();
            $table->string('line_1', 180)->nullable();
            $table->string('line_2', 180)->nullable();
            $table->string('locality', 120)->nullable();
            $table->string('region', 120)->nullable();
            $table->string('postal_code', 32)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->text('normalized')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();
            $table->index(['agency_id', 'locality', 'region']);
        });

        Schema::create('properties', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('property_type_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('address_id')->nullable()->constrained('addresses')->nullOnDelete();
            $table->unsignedSmallInteger('bedrooms')->nullable();
            $table->decimal('bathrooms', 4, 1)->nullable();
            $table->decimal('interior_area_sqm', 12, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['agency_id', 'property_type_id']);
        });

        Schema::create('property_identifiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('property_id')->constrained()->cascadeOnDelete();
            $table->string('scheme', 80);
            $table->string('value', 180);
            $table->string('source', 120)->default('agency');
            $table->timestamps();
            $table->unique(['property_id', 'scheme', 'value']);
            $table->index(['scheme', 'value', 'source']);
        });

        Schema::create('listings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('property_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference', 100);
            $table->string('intent', 24);
            $table->string('status', 32)->default('draft');
            $table->string('title', 160)->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('price_amount_minor')->nullable();
            $table->char('price_currency', 3)->default('USD');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedTinyInteger('quality_score')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['agency_id', 'reference']);
            $table->index(['agency_id', 'status', 'updated_at', 'id']);
        });

        Schema::create('property_feature_values', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('property_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('property_feature_definition_id')->constrained()->restrictOnDelete();
            $table->json('value');
            $table->timestamps();
            $table->unique(['property_id', 'property_feature_definition_id'], 'property_feature_unique');
        });

        Schema::create('property_amenities', function (Blueprint $table) {
            $table->foreignUuid('property_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('amenity_id')->constrained()->restrictOnDelete();
            $table->primary(['property_id', 'amenity_id']);
        });

        Schema::create('listing_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('listing_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('snapshot');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['listing_id', 'version']);
        });

        Schema::create('listing_status_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('listing_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['listing_id', 'created_at']);
        });

        Schema::create('price_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('listing_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('effective_at')->useCurrent();
            $table->index(['listing_id', 'effective_at']);
        });

        Schema::create('media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('listing_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 160);
            $table->string('original_name', 255);
            $table->string('mime_type', 80);
            $table->unsignedBigInteger('byte_size');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedSmallInteger('position');
            $table->char('checksum_sha256', 64);
            $table->string('storage_key', 500);
            $table->string('alt_text', 300)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['listing_id', 'idempotency_key']);
            $table->index(['listing_id', 'position']);
            $table->index(['agency_id', 'created_at']);
        });

        Schema::create('media_derivatives', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('media_id')->constrained('media')->cascadeOnDelete();
            $table->string('kind', 40);
            $table->string('storage_key', 500);
            $table->string('mime_type', 80);
            $table->unsignedBigInteger('byte_size');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->timestamps();
            $table->unique(['media_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_derivatives');
        Schema::dropIfExists('media');
        Schema::dropIfExists('price_history');
        Schema::dropIfExists('listing_status_history');
        Schema::dropIfExists('listing_versions');
        Schema::dropIfExists('property_amenities');
        Schema::dropIfExists('property_feature_values');
        Schema::dropIfExists('listings');
        Schema::dropIfExists('property_identifiers');
        Schema::dropIfExists('properties');
        Schema::dropIfExists('addresses');
        Schema::dropIfExists('property_feature_definitions');
        Schema::dropIfExists('amenities');
        Schema::dropIfExists('property_types');
    }
};
