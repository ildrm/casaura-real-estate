<?php

namespace Tests\Feature\Api;

use App\Domain\Search\PublicListingProjector;
use App\Models\Listing;
use App\Models\PlanEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesAgencyTenant;
use Tests\TestCase;

class DataIntegrationsTest extends TestCase
{
    use CreatesAgencyTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('listing_media');
        PlanEntitlement::query()->firstOrCreate(
            ['plan_id' => DB::table('plans')->where('slug', 'launch')->value('id'), 'key' => 'mls'],
            ['value' => true],
        )->update(['value' => true]);
        config(['integrations.secrets.reso_test' => 'client-secret']);
    }

    /** Phase 7 AC-1 through AC-5. */
    public function test_reso_connection_mapping_and_sync_are_safe_versioned_and_idempotent(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        $this->actAsAgencyOwner($owner);
        Http::fake([
            'https://identity.example.test/oauth/token' => Http::response([
                'access_token' => 'provider-access-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ]),
            'https://reso.example.test/odata/$metadata' => Http::response(<<<'XML'
                <?xml version="1.0" encoding="utf-8"?>
                <edmx:Edmx xmlns:edmx="http://docs.oasis-open.org/odata/ns/edmx" Version="4.0">
                  <edmx:DataServices>
                    <Schema xmlns="http://docs.oasis-open.org/odata/ns/edm" Namespace="RESO.OData">
                      <EntityType Name="Property">
                        <Key><PropertyRef Name="ListingKey" /></Key>
                        <Property Name="ListingKey" Type="Edm.String" Nullable="false" />
                        <Property Name="ListPrice" Type="Edm.Decimal" />
                        <Property Name="ModificationTimestamp" Type="Edm.DateTimeOffset" />
                      </EntityType>
                    </Schema>
                  </edmx:DataServices>
                </edmx:Edmx>
                XML, 200, ['Content-Type' => 'application/xml']),
            'https://reso.example.test/odata/Property*' => Http::sequence()
                ->push([
                    'value' => [[
                        'ListingKey' => 'reso-100',
                        'ListingId' => 'MLS-100',
                        'StandardStatus' => 'Active',
                        'PropertyType' => 'Residential',
                        'PropertySubType' => 'SingleFamilyResidence',
                        'ListPrice' => 725000,
                        'BedroomsTotal' => 4,
                        'BathroomsTotalDecimal' => 3,
                        'LivingArea' => 2400,
                        'LivingAreaUnits' => 'SquareFeet',
                        'UnparsedAddress' => '10 Main Street',
                        'City' => 'Austin',
                        'StateOrProvince' => 'TX',
                        'PostalCode' => '78701',
                        'Country' => 'US',
                        'Currency' => 'USD',
                        'PublicRemarks' => 'A licensed feed property with current public facts.',
                        'ModificationTimestamp' => '2026-08-23T10:00:00Z',
                    ], [
                        'ListingKey' => 'reso-101',
                        'ListingId' => 'MLS-101',
                        'StandardStatus' => 'Active',
                        'PropertyType' => 'Residential',
                        'PropertySubType' => 'SingleFamilyResidence',
                        'ListPrice' => 730000,
                        'BedroomsTotal' => 4,
                        'BathroomsTotalDecimal' => 3,
                        'LivingArea' => 2410,
                        'LivingAreaUnits' => 'SquareFeet',
                        'UnparsedAddress' => '10 Main Street',
                        'City' => 'Austin',
                        'StateOrProvince' => 'TX',
                        'PostalCode' => '78701',
                        'Country' => 'US',
                        'Currency' => 'USD',
                        'PublicRemarks' => 'A possible duplicate from the licensed feed.',
                        'ModificationTimestamp' => '2026-08-23T10:05:00Z',
                    ]],
                    '@odata.nextLink' => 'https://reso.example.test/odata/Property?$skiptoken=next',
                ], 200, ['OData-Version' => '4.01'])
                ->push(['value' => []], 200, ['OData-Version' => '4.01'])
                ->push(['value' => [[
                    'ListingKey' => 'reso-100',
                    'ListingId' => 'MLS-100',
                    'StandardStatus' => 'Withdrawn',
                    'PropertyType' => 'Residential',
                    'PropertySubType' => 'SingleFamilyResidence',
                    'ListPrice' => 725000,
                    'BedroomsTotal' => 4,
                    'BathroomsTotalDecimal' => 3,
                    'LivingArea' => 2400,
                    'LivingAreaUnits' => 'SquareFeet',
                    'UnparsedAddress' => '10 Main Street',
                    'City' => 'Austin',
                    'StateOrProvince' => 'TX',
                    'PostalCode' => '78701',
                    'Country' => 'US',
                    'Currency' => 'USD',
                    'PublicRemarks' => 'A licensed feed property that is no longer active.',
                    'ModificationTimestamp' => '2026-08-23T11:00:00Z',
                ]]], 200, ['OData-Version' => '4.01']),
        ]);

        $connection = $this->postJson('/api/v1/integrations/connections', [
            'name' => 'Licensed RESO feed',
            'provider' => 'reso',
            'base_url' => 'https://reso.example.test/odata/',
            'token_url' => 'https://identity.example.test/oauth/token',
            'client_id' => 'casaura-test',
            'secret_reference' => 'reso_test',
            'resources' => ['Property'],
            'rights' => ['display' => true, 'photos' => false, 'attribution' => 'Example MLS'],
        ], $this->agencyHeaders($agency))->assertCreated()->json('data');

        $this->assertArrayNotHasKey('client_secret', $connection);
        $this->assertStringNotContainsString('client-secret', json_encode($connection, JSON_THROW_ON_ERROR));
        $this->getJson("/api/v1/integrations/connections/{$connection['id']}/metadata", $this->agencyHeaders($agency))
            ->assertOk()
            ->assertJsonPath('data.resources.0.name', 'Property')
            ->assertJsonPath('data.resources.0.fields.1.name', 'ListPrice');
        $mapping = $this->postJson("/api/v1/integrations/connections/{$connection['id']}/mappings", [
            'resource' => 'Property',
            'fields' => [
                'external_id' => 'ListingKey',
                'reference' => 'ListingId',
                'status' => 'StandardStatus',
                'property_type' => 'PropertyType',
                'property_subtype' => 'PropertySubType',
                'price' => 'ListPrice',
                'currency' => 'Currency',
                'bedrooms' => 'BedroomsTotal',
                'bathrooms' => 'BathroomsTotalDecimal',
                'area' => 'LivingArea',
                'area_unit' => 'LivingAreaUnits',
                'line_1' => 'UnparsedAddress',
                'locality' => 'City',
                'region' => 'StateOrProvince',
                'postal_code' => 'PostalCode',
                'country_code' => 'Country',
                'description' => 'PublicRemarks',
                'modified_at' => 'ModificationTimestamp',
            ],
        ], $this->agencyHeaders($agency))->assertCreated()->json('data');
        $this->assertSame(1, $mapping['version']);

        $headers = $this->agencyHeaders($agency, ['Idempotency-Key' => 'sync-reso-1']);
        $sync = $this->postJson("/api/v1/integrations/connections/{$connection['id']}/syncs", [
            'mode' => 'incremental',
        ], $headers)->assertAccepted()->json('data');
        $replay = $this->postJson("/api/v1/integrations/connections/{$connection['id']}/syncs", [
            'mode' => 'incremental',
        ], $headers)->assertOk()->json('data');

        $this->assertSame($sync['id'], $replay['id']);
        $this->assertSame('completed', $sync['status']);
        $this->assertSame(2, $sync['records_fetched']);
        $this->assertSame(1, $sync['records_imported']);
        $this->assertSame(1, $sync['records_skipped']);
        $this->assertSame('2026-08-23T10:05:00+00:00', $sync['end_cursor']);
        $this->assertDatabaseCount('provider_connections', 1);
        $this->assertDatabaseCount('data_source_records', 2);
        $this->assertDatabaseCount('listings', 1);
        $this->assertDatabaseCount('duplicate_candidates', 1);
        $this->assertDatabaseHas('data_source_records', [
            'external_record_id' => 'reso-101',
            'outcome' => 'duplicate_review',
        ]);
        $this->assertDatabaseHas('listings', ['agency_id' => $agency->id, 'reference' => 'MLS-100']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'integration.sync_completed',
            'request_id' => $sync['id'],
        ]);

        $candidate = DB::table('duplicate_candidates')->first();
        $this->patchJson("/api/v1/integrations/duplicate-candidates/{$candidate->id}", [
            'decision' => 'linked',
            'version' => 1,
        ], $this->agencyHeaders($agency))->assertOk();
        $this->assertDatabaseHas('data_source_records', [
            'external_record_id' => 'reso-101',
            'outcome' => 'linked',
        ]);
        $this->patchJson("/api/v1/integrations/duplicate-candidates/{$candidate->id}", [
            'decision' => 'reverse',
            'version' => 2,
        ], $this->agencyHeaders($agency))->assertOk();
        $this->assertDatabaseHas('data_source_records', [
            'external_record_id' => 'reso-101',
            'outcome' => 'duplicate_review',
        ]);

        $listing = Listing::query()->where('reference', 'MLS-100')->firstOrFail();
        $listing->update(['status' => 'published', 'published_at' => now()]);
        app(PublicListingProjector::class)->projectNow($listing->fresh());
        $withdrawal = $this->postJson("/api/v1/integrations/connections/{$connection['id']}/syncs", [
            'mode' => 'incremental',
        ], $this->agencyHeaders($agency, ['Idempotency-Key' => 'sync-reso-2']))
            ->assertAccepted()->json('data');
        $this->assertSame('2026-08-23T10:05:00+00:00', $withdrawal['start_cursor']);
        $this->assertSame('2026-08-23T11:00:00+00:00', $withdrawal['end_cursor']);
        $this->assertDatabaseHas('listings', ['id' => $listing->id, 'status' => 'withdrawn']);
        $this->assertDatabaseMissing('search_documents', ['listing_id' => $listing->id]);
        Http::assertSentCount(7);
    }

    /** Phase 7 AC-6 and AC-7. */
    public function test_duplicate_review_is_tenant_scoped_audited_and_reversible(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        [$otherOwner, $otherAgency] = $this->createAgencyOwner('Other Integrations');
        $this->actAsAgencyOwner($owner);
        $candidateId = (string) str()->uuid();
        DB::table('duplicate_candidates')->insert([
            'id' => $candidateId,
            'agency_id' => $agency->id,
            'left_property_id' => null,
            'right_property_id' => null,
            'score' => 0.81,
            'reasons' => json_encode(['normalized_address']),
            'status' => 'pending',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actAsAgencyOwner($otherOwner);
        $this->getJson('/api/v1/integrations/duplicate-candidates', $this->agencyHeaders($otherAgency))
            ->assertOk()->assertJsonCount(0, 'data');
        $this->actAsAgencyOwner($owner);
        $reviewed = $this->patchJson("/api/v1/integrations/duplicate-candidates/{$candidateId}", [
            'decision' => 'linked',
            'version' => 1,
        ], $this->agencyHeaders($agency))->assertOk()->json('data');
        $this->assertSame('linked', $reviewed['status']);
        $this->patchJson("/api/v1/integrations/duplicate-candidates/{$candidateId}", [
            'decision' => 'reverse',
            'version' => 2,
        ], $this->agencyHeaders($agency))->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertDatabaseHas('audit_logs', ['action' => 'integration.duplicate_reversed']);
    }
}
