<?php

use App\Models\Address;
use App\Models\Agency;
use App\Models\AgencyMember;
use App\Models\BillingCustomer;
use App\Models\FieldMapping;
use App\Models\Invoice;
use App\Models\Listing;
use App\Models\Plan;
use App\Models\PromotionCampaign;
use App\Models\PromotionPolicy;
use App\Models\Property;
use App\Models\PropertyIdentifier;
use App\Models\PropertyType;
use App\Models\ProviderConnection;
use App\Models\RealEstateDataProvider;
use App\Models\SearchDocument;
use App\Models\SyncJob;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment(['local', 'testing'])) {
    fwrite(STDERR, "The Phase 7-10 E2E fixture is available only in local and testing environments.\n");
    exit(2);
}

$agency = Agency::query()->find((string) ($argv[1] ?? ''));
if (! $agency) {
    fwrite(STDERR, "No E2E agency exists for the requested ID.\n");
    exit(3);
}
$owner = AgencyMember::query()->where('agency_id', $agency->id)->where('status', 'active')->firstOrFail()->user;
$plan = Plan::query()->where('slug', 'professional')->firstOrFail();

$result = DB::transaction(function () use ($agency, $owner, $plan): array {
    DB::table('subscriptions')->where('agency_id', $agency->id)->update([
        'plan_id' => $plan->id,
        'status' => 'active',
        'billing_status' => 'paid',
        'current_period_ends_at' => now()->addMonth(),
        'updated_at' => now(),
    ]);
    DB::table('feature_flags')->whereIn('key', [
        'comparisons', 'collaborative_collections', 'mls', 'ai_search',
        'ai_listing_writer', 'sponsored_listings', 'payments',
    ])->update(['default_enabled' => true, 'updated_at' => now()]);

    $type = PropertyType::query()->where('slug', 'house')->firstOrFail();
    $published = [];
    foreach ([
        ['Release Garden Residence', 79500000, 3, 2.0, 175.0, 30.2672, -97.7431],
        ['Canopy Courtyard House', 84500000, 3, 2.5, 188.0, 30.2691, -97.7398],
    ] as $index => [$title, $price, $bedrooms, $bathrooms, $area, $latitude, $longitude]) {
        $address = Address::query()->create([
            'agency_id' => $agency->id,
            'line_1' => (100 + $index).' Release Avenue',
            'locality' => 'Austin',
            'region' => 'TX',
            'postal_code' => '78701',
            'country_code' => 'US',
            'normalized' => (100 + $index).' Release Avenue, Austin, TX, 78701, US',
            'latitude' => $latitude,
            'longitude' => $longitude,
            'public_location_policy' => 'approximate',
            'public_latitude' => $latitude,
            'public_longitude' => $longitude,
        ]);
        $property = Property::query()->create([
            'agency_id' => $agency->id,
            'property_type_id' => $type->id,
            'address_id' => $address->id,
            'bedrooms' => $bedrooms,
            'bathrooms' => $bathrooms,
            'interior_area_sqm' => $area,
        ]);
        $reference = 'RELEASE-'.Str::upper(Str::random(8));
        PropertyIdentifier::query()->create([
            'property_id' => $property->id,
            'scheme' => 'agency_reference',
            'value' => $reference,
            'source' => 'agency',
        ]);
        $listing = Listing::query()->create([
            'agency_id' => $agency->id,
            'property_id' => $property->id,
            'created_by_user_id' => $owner->id,
            'reference' => $reference,
            'slug' => Str::slug($title),
            'intent' => 'sale',
            'status' => 'published',
            'title' => $title,
            'description' => 'A current published home with grounded facts, generous natural light, and a private outdoor setting.',
            'price_amount_minor' => $price,
            'price_currency' => 'USD',
            'version' => 1,
            'quality_score' => 100,
            'published_at' => now()->subDays(5 + $index),
        ]);
        SearchDocument::query()->create([
            'listing_id' => $listing->id,
            'property_id' => $property->id,
            'agency_id' => $agency->id,
            'projection_version' => 1,
            'slug' => $listing->slug,
            'status' => 'published',
            'intent' => 'sale',
            'title' => $title,
            'description' => $listing->description,
            'price_amount_minor' => $price,
            'price_currency' => 'USD',
            'property_type_slug' => 'house',
            'property_type_name' => 'House',
            'bedrooms' => $bedrooms,
            'bathrooms' => $bathrooms,
            'interior_area_sqm' => $area,
            'locality' => 'Austin',
            'region' => 'TX',
            'country_code' => 'US',
            'location_policy' => 'approximate',
            'public_latitude' => $latitude,
            'public_longitude' => $longitude,
            'agency_name' => $agency->name,
            'agency_slug' => $agency->slug,
            'agency_verified' => true,
            'listed_at' => $listing->published_at,
            'amenities' => ['garden', 'garage'],
            'features' => ['year_built' => 2020 + $index],
            'media' => [],
            'search_text' => "{$title} Austin TX house garden garage",
        ]);
        $published[] = $listing;
    }

    $draftAddress = Address::query()->create([
        'agency_id' => $agency->id,
        'line_1' => '300 Draft Lane',
        'locality' => 'Austin',
        'region' => 'TX',
        'postal_code' => '78702',
        'country_code' => 'US',
        'normalized' => '300 Draft Lane, Austin, TX, 78702, US',
        'public_location_policy' => 'hidden',
    ]);
    $draftProperty = Property::query()->create([
        'agency_id' => $agency->id,
        'property_type_id' => $type->id,
        'address_id' => $draftAddress->id,
        'bedrooms' => 2,
        'bathrooms' => 2,
        'interior_area_sqm' => 120,
    ]);
    $draft = Listing::query()->create([
        'agency_id' => $agency->id,
        'property_id' => $draftProperty->id,
        'created_by_user_id' => $owner->id,
        'reference' => 'DRAFT-'.Str::upper(Str::random(8)),
        'slug' => 'draft-release-home',
        'intent' => 'sale',
        'status' => 'draft',
        'title' => 'Draft release home',
        'description' => 'A factual draft ready for a human-reviewed writing suggestion.',
        'price_amount_minor' => 62500000,
        'price_currency' => 'USD',
        'version' => 1,
        'quality_score' => 70,
    ]);
    PropertyIdentifier::query()->create([
        'property_id' => $draftProperty->id,
        'scheme' => 'agency_reference',
        'value' => $draft->reference,
        'source' => 'agency',
    ]);

    $provider = RealEstateDataProvider::query()->where('key', 'reso')->firstOrFail();
    $connection = ProviderConnection::query()->create([
        'agency_id' => $agency->id,
        'provider_id' => $provider->id,
        'name' => 'Austin licensed feed',
        'base_url' => 'https://reso.example.test/odata/',
        'token_url' => 'https://identity.example.test/oauth/token',
        'client_id' => 'e2e-client',
        'secret_reference' => 'reso.e2e.client-secret',
        'resources' => ['Property'],
        'rights_snapshot' => ['display' => true, 'photos' => false, 'attribution' => 'Listing data © Austin Test MLS'],
        'data_dictionary_version' => '2.0',
        'is_enabled' => true,
        'last_sync_status' => 'completed',
        'last_synced_at' => now()->subMinutes(8),
    ]);
    FieldMapping::query()->create([
        'provider_connection_id' => $connection->id,
        'resource' => 'Property',
        'version' => 1,
        'fields' => [
            'external_id' => 'ListingKey', 'reference' => 'ListingId', 'status' => 'StandardStatus',
            'property_type' => 'PropertyType', 'price' => 'ListPrice', 'currency' => 'Currency',
            'bedrooms' => 'BedroomsTotal', 'bathrooms' => 'BathroomsTotalDecimal',
            'line_1' => 'UnparsedAddress', 'locality' => 'City', 'region' => 'StateOrProvince',
            'modified_at' => 'ModificationTimestamp',
        ],
        'created_by_user_id' => $owner->id,
        'activated_at' => now()->subHour(),
    ]);
    $sync = SyncJob::query()->create([
        'provider_connection_id' => $connection->id,
        'mode' => 'incremental',
        'status' => 'completed',
        'idempotency_key' => 'e2e-release-sync',
        'payload_hash' => hash('sha256', 'incremental'),
        'end_cursor' => now()->subMinutes(8)->toIso8601String(),
        'records_fetched' => 248,
        'records_imported' => 241,
        'records_skipped' => 6,
        'records_failed' => 1,
        'started_at' => now()->subMinutes(9),
        'completed_at' => now()->subMinutes(8),
    ]);
    DB::table('import_errors')->insert([
        'id' => (string) Str::uuid(),
        'sync_job_id' => $sync->id,
        'field' => 'currency',
        'code' => 'PROVIDER_CURRENCY_UNSUPPORTED',
        'retryable' => false,
        'created_at' => now()->subMinutes(8),
        'updated_at' => now()->subMinutes(8),
    ]);
    DB::table('duplicate_candidates')->insert([
        'id' => (string) Str::uuid(),
        'agency_id' => $agency->id,
        'left_property_id' => $published[0]->property_id,
        'right_property_id' => $published[1]->property_id,
        'score' => 0.82,
        'reasons' => json_encode(['normalized_address', 'similar_reference'], JSON_THROW_ON_ERROR),
        'status' => 'pending',
        'version' => 1,
        'created_at' => now()->subMinutes(7),
        'updated_at' => now()->subMinutes(7),
    ]);

    BillingCustomer::query()->create([
        'agency_id' => $agency->id,
        'provider' => 'deterministic',
        'provider_customer_id' => 'cus_test_'.Str::lower(Str::random(12)),
        'version' => 1,
    ]);
    $subscriptionId = DB::table('subscriptions')->where('agency_id', $agency->id)->value('id');
    Invoice::query()->create([
        'agency_id' => $agency->id,
        'subscription_id' => $subscriptionId,
        'provider' => 'stripe',
        'provider_invoice_id' => 'in_test_'.Str::lower(Str::random(12)),
        'number' => 'INV-RELEASE-001',
        'status' => 'paid',
        'subtotal_minor' => 4900,
        'tax_minor' => 404,
        'total_minor' => 5304,
        'currency' => 'USD',
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
        'hosted_invoice_url' => 'https://invoice.stripe.test/release',
        'invoice_pdf_url' => 'https://invoice.stripe.test/release.pdf',
        'provider_updated_at' => now(),
    ]);
    $policy = PromotionPolicy::query()->create([
        'family_id' => (string) Str::uuid(),
        'version' => 1,
        'name' => 'Search featured placement',
        'placement' => 'search',
        'label' => 'Sponsored',
        'disclosure' => 'Paid placement; organic ranking is unchanged.',
        'eligible_plan_ids' => [$plan->id],
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addMonth(),
        'max_impressions' => 1000,
        'status' => 'active',
        'created_by_user_id' => $owner->id,
    ]);
    PromotionCampaign::query()->create([
        'agency_id' => $agency->id,
        'listing_id' => $published[0]->id,
        'promotion_policy_id' => $policy->id,
        'placement' => 'search',
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addWeek(),
        'impression_cap' => 100,
        'impression_count' => 4,
        'status' => 'active',
        'version' => 1,
    ]);

    return [
        'published_listing_ids' => array_map(fn (Listing $listing) => $listing->id, $published),
        'draft_listing_id' => $draft->id,
    ];
});

echo json_encode($result, JSON_THROW_ON_ERROR);
