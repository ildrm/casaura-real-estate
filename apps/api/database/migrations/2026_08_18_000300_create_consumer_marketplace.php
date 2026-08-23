<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table): void {
            $table->string('slug', 180)->nullable()->after('reference')->index();
        });
        DB::table('listings')->orderBy('id')->get(['id', 'title'])->each(function (object $listing): void {
            DB::table('listings')->where('id', $listing->id)->update([
                'slug' => Str::slug($listing->title ?: 'property') ?: 'property',
            ]);
        });

        Schema::table('addresses', function (Blueprint $table): void {
            $table->string('public_location_policy', 24)->default('approximate')->after('longitude');
            $table->decimal('public_latitude', 10, 7)->nullable()->after('public_location_policy');
            $table->decimal('public_longitude', 10, 7)->nullable()->after('public_latitude');
        });
        DB::table('addresses')->update(['public_location_policy' => 'hidden']);

        Schema::create('search_documents', function (Blueprint $table): void {
            $table->foreignUuid('listing_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignUuid('property_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('agency_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('projection_version');
            $table->string('slug', 180)->index();
            $table->string('status', 32)->index();
            $table->string('intent', 24)->index();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('price_amount_minor')->nullable();
            $table->char('price_currency', 3)->nullable();
            $table->string('property_type_slug', 80)->index();
            $table->string('property_type_name', 120);
            $table->unsignedSmallInteger('bedrooms')->nullable();
            $table->decimal('bathrooms', 4, 1)->nullable();
            $table->decimal('interior_area_sqm', 12, 2)->nullable();
            $table->string('locality', 120)->nullable()->index();
            $table->string('region', 120)->nullable()->index();
            $table->char('country_code', 2)->nullable()->index();
            $table->string('location_policy', 24)->default('hidden');
            $table->decimal('public_latitude', 10, 7)->nullable();
            $table->decimal('public_longitude', 10, 7)->nullable();
            $table->string('agency_name', 160);
            $table->string('agency_slug', 180);
            $table->boolean('agency_verified')->default(false)->index();
            $table->timestamp('listed_at')->nullable()->index();
            $table->json('amenities');
            $table->json('features');
            $table->json('media');
            $table->text('search_text');
            $table->timestamps();
            $table->index(['status', 'intent', 'price_currency', 'price_amount_minor'], 'search_price_filter');
            $table->index(['status', 'property_type_slug', 'bedrooms', 'bathrooms'], 'search_fact_filter');
            $table->index(['status', 'listed_at', 'listing_id'], 'search_newest_cursor');
        });

        Schema::create('search_projection_outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('listing_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('projection_version');
            $table->string('operation', 16);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['listing_id', 'projection_version', 'operation'], 'search_projection_operation_unique');
            $table->index(['processed_at', 'available_at']);
        });

        Schema::create('favorites', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('listing_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'listing_id']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('property_reactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('listing_id')->constrained()->cascadeOnDelete();
            $table->string('reaction', 16);
            $table->timestamps();
            $table->unique(['user_id', 'listing_id']);
            $table->index(['user_id', 'reaction', 'updated_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
            DB::statement('ALTER TABLE addresses ADD COLUMN public_location geography(Point,4326) GENERATED ALWAYS AS (CASE WHEN public_latitude IS NULL OR public_longitude IS NULL THEN NULL ELSE ST_SetSRID(ST_MakePoint(public_longitude::double precision, public_latitude::double precision), 4326)::geography END) STORED');
            DB::statement('CREATE INDEX addresses_public_location_gist ON addresses USING GIST (public_location)');
            DB::statement('ALTER TABLE search_documents ADD COLUMN public_location geography(Point,4326) GENERATED ALWAYS AS (CASE WHEN public_latitude IS NULL OR public_longitude IS NULL THEN NULL ELSE ST_SetSRID(ST_MakePoint(public_longitude::double precision, public_latitude::double precision), 4326)::geography END) STORED');
            DB::statement('CREATE INDEX search_documents_public_location_gist ON search_documents USING GIST (public_location)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('property_reactions');
        Schema::dropIfExists('favorites');
        Schema::dropIfExists('search_projection_outbox');
        Schema::dropIfExists('search_documents');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS addresses_public_location_gist');
            DB::statement('ALTER TABLE addresses DROP COLUMN IF EXISTS public_location');
        }
        Schema::table('addresses', function (Blueprint $table): void {
            $table->dropColumn(['public_location_policy', 'public_latitude', 'public_longitude']);
        });
        Schema::table('listings', function (Blueprint $table): void {
            $table->dropColumn('slug');
        });
    }
};
