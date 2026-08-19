<?php

namespace Tests\Support;

use App\Models\Agency;
use App\Models\AgencyMember;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;

trait CreatesAgencyTenant
{
    /** @return array{User, Agency} */
    protected function createAgencyOwner(string $name = 'Greenway Realty'): array
    {
        $user = User::factory()->create();
        $agency = Agency::query()->create([
            'owner_user_id' => $user->id,
            'name' => $name,
            'slug' => str($name)->slug().'-'.str()->lower(str()->random(6)),
            'email' => $user->email,
        ]);
        $membership = AgencyMember::query()->create([
            'agency_id' => $agency->id,
            'user_id' => $user->id,
            'status' => 'active',
            'accepted_at' => now(),
        ]);
        $membership->roles()->attach(Role::query()->where('slug', 'agency_owner')->firstOrFail());
        if ($plan = Plan::query()->where('slug', 'launch')->first()) {
            Subscription::query()->firstOrCreate(['agency_id' => $agency->id], [
                'plan_id' => $plan->id, 'status' => 'active', 'billing_status' => 'not_required',
            ]);
        }

        return [$user, $agency];
    }

    protected function actAsAgencyOwner(User $user): void
    {
        Sanctum::actingAs($user);
    }

    /** @return array<string, string> */
    protected function agencyHeaders(Agency $agency, array $extra = []): array
    {
        return array_merge([
            'Agency-ID' => $agency->id,
            'Request-ID' => (string) str()->uuid(),
        ], $extra);
    }

    /** @return array<string, mixed> */
    protected function validListingPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'reference' => 'GR-'.str()->upper(str()->random(8)),
            'intent' => 'sale',
            'property_type_slug' => 'house',
            'title' => 'Modern family home in Oakridge',
            'description' => 'A carefully maintained family home with generous natural light, thoughtful updates, and convenient access to parks, schools, and everyday amenities.',
            'price' => ['amount_minor' => 139500000, 'currency' => 'USD'],
            'bedrooms' => 3,
            'bathrooms' => 2.5,
            'interior_area' => ['value' => 2120, 'unit' => 'sq_ft'],
            'address' => [
                'line_1' => '241 Oakridge Drive',
                'locality' => 'Austin',
                'region' => 'TX',
                'postal_code' => '78704',
                'country_code' => 'US',
            ],
            'features' => ['year_built' => 2018, 'parking_spaces' => 2],
            'amenity_slugs' => ['garden', 'garage'],
        ], $overrides);
    }

    /** @return array<string, mixed> */
    protected function createPublishedListing(User $user, Agency $agency, array $overrides = []): array
    {
        $this->actAsAgencyOwner($user);
        $listing = $this->postJson('/api/v1/listings', $this->validListingPayload($overrides), $this->agencyHeaders($agency))
            ->assertCreated()->json('data');

        foreach (range(1, 5) as $position) {
            $this->withHeaders($this->agencyHeaders($agency, ['Idempotency-Key' => "publish-{$listing['id']}-{$position}"]))
                ->post("/api/v1/listings/{$listing['id']}/media", [
                    'file' => UploadedFile::fake()->image("published-{$position}.jpg", 640, 480),
                    'alt_text' => "Published property view {$position}",
                ])->assertCreated();
        }

        $this->postJson("/api/v1/listings/{$listing['id']}/submit", [], $this->agencyHeaders($agency))->assertOk();

        return $this->postJson("/api/v1/listings/{$listing['id']}/publish", [], $this->agencyHeaders($agency))
            ->assertOk()->json('data');
    }
}
