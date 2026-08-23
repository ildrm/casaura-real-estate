<?php

namespace Database\Seeders;

use App\Models\Amenity;
use App\Models\FeatureFlag;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\PropertyFeatureDefinition;
use App\Models\PropertyType;
use App\Models\RealEstateDataProvider;
use App\Models\Role;
use App\Models\Setting;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $permissions = [
            'agency.manage_profile' => 'agency',
            'agency.manage_members' => 'agency',
            'property.create' => 'property',
            'property.publish' => 'property',
            'property.delete' => 'property',
            'listing.view' => 'listing',
            'listing.create' => 'listing',
            'listing.update' => 'listing',
            'listing.publish' => 'listing',
            'listing.delete' => 'listing',
            'media.manage' => 'listing',
            'lead.manage' => 'lead',
            'analytics.view' => 'analytics',
            'billing.manage' => 'billing',
            'integration.configure' => 'integration',
            'comment.moderate' => 'moderation',
            'platform.settings' => 'platform',
            'audit.view' => 'audit',
        ];

        $permissionModels = collect($permissions)->mapWithKeys(function (string $group, string $name) {
            $permission = Permission::query()->updateOrCreate(
                ['name' => $name],
                ['group' => $group],
            );

            return [$name => $permission];
        });

        $roleTemplates = [
            'agency_owner' => [
                'agency.manage_profile', 'agency.manage_members', 'property.create',
                'property.publish', 'property.delete', 'lead.manage', 'analytics.view',
                'billing.manage', 'integration.configure', 'audit.view', 'listing.view',
                'listing.create', 'listing.update', 'listing.publish', 'listing.delete', 'media.manage',
            ],
            'agency_manager' => [
                'agency.manage_profile', 'agency.manage_members', 'property.create',
                'property.publish', 'property.delete', 'lead.manage', 'analytics.view',
                'integration.configure', 'audit.view', 'listing.view', 'listing.create',
                'listing.update', 'listing.publish', 'listing.delete', 'media.manage',
            ],
            'agent' => [
                'property.create', 'property.publish', 'lead.manage', 'analytics.view',
                'listing.view', 'listing.create', 'listing.update', 'media.manage',
            ],
            'content_manager' => [
                'agency.manage_profile', 'property.create', 'listing.view',
                'listing.create', 'listing.update', 'media.manage',
            ],
            'agency_analyst' => ['analytics.view'],
            'moderator' => ['comment.moderate', 'audit.view'],
            'support_administrator' => ['audit.view'],
            'platform_administrator' => array_keys($permissions),
            'super_administrator' => array_keys($permissions),
        ];

        foreach ($roleTemplates as $slug => $rolePermissions) {
            $role = Role::query()->updateOrCreate(
                ['scope' => 'platform', 'slug' => $slug],
                ['name' => str($slug)->replace('_', ' ')->title(), 'is_system' => true],
            );

            $role->permissions()->sync($permissionModels->only($rolePermissions)->pluck('id'));
        }

        $plan = Plan::query()->updateOrCreate(
            ['slug' => 'launch'],
            [
                'name' => 'Launch',
                'is_active' => true,
                'is_public' => true,
                'price_amount_minor' => 0,
                'price_currency' => 'USD',
                'billing_interval' => 'month',
            ],
        );

        $professional = Plan::query()->updateOrCreate(
            ['slug' => 'professional'],
            [
                'name' => 'Professional',
                'is_active' => true,
                'is_public' => true,
                'price_amount_minor' => 4900,
                'price_currency' => 'USD',
                'billing_interval' => 'month',
                'provider_price_id' => config('billing.stripe.professional_price_id'),
            ],
        );

        foreach ([
            'agency_storefronts' => true,
            'team_management' => true,
            'listing_creation' => true,
            'media_storage_mb' => true,
            'messaging' => true,
            'viewings' => true,
        ] as $key => $value) {
            PlanEntitlement::query()->updateOrCreate(
                ['plan_id' => $plan->id, 'key' => $key],
                ['value' => $value, 'quota' => match ($key) {
                    'team_management' => 50,
                    'listing_creation' => 500,
                    'media_storage_mb' => 5120,
                    default => null,
                }],
            );
        }

        foreach ([
            'agency_storefronts' => [true, null],
            'team_management' => [true, 100],
            'listing_creation' => [true, 2000],
            'media_storage_mb' => [true, 20480],
            'messaging' => [true, null],
            'viewings' => [true, null],
            'comparisons' => [true, null],
            'collaborative_collections' => [true, null],
            'mls' => [true, null],
            'ai_search' => [true, null],
            'ai_listing_writer' => [true, null],
            'sponsored_listings' => [true, null],
            'payments' => [true, null],
        ] as $key => [$value, $quota]) {
            PlanEntitlement::query()->updateOrCreate(
                ['plan_id' => $professional->id, 'key' => $key],
                ['value' => $value, 'quota' => $quota],
            );
        }

        RealEstateDataProvider::query()->updateOrCreate(
            ['key' => 'reso'],
            [
                'name' => 'RESO Web API',
                'adapter' => 'reso_odata',
                'protocol' => 'OData 4.01',
                'is_active' => true,
                'capabilities' => ['read', 'metadata', 'incremental'],
            ],
        );

        foreach ([
            'house' => ['House', 'residential'],
            'apartment' => ['Apartment', 'residential'],
            'townhouse' => ['Townhouse', 'residential'],
            'land' => ['Land', 'land'],
            'commercial' => ['Commercial', 'commercial'],
        ] as $slug => [$name, $category]) {
            PropertyType::query()->updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'category' => $category, 'is_active' => true],
            );
        }

        foreach ([
            'garden' => ['Garden', 'outdoor'],
            'garage' => ['Garage', 'parking'],
            'pool' => ['Pool', 'leisure'],
            'balcony' => ['Balcony', 'outdoor'],
            'elevator' => ['Elevator', 'access'],
            'air_conditioning' => ['Air conditioning', 'comfort'],
        ] as $slug => [$name, $group]) {
            Amenity::query()->updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'group' => $group, 'is_active' => true],
            );
        }

        foreach ([
            'year_built' => ['Year built', 'integer', 'year', ['min' => 1700, 'max' => 2100]],
            'parking_spaces' => ['Parking spaces', 'integer', 'spaces', ['min' => 0, 'max' => 100]],
            'energy_rating' => ['Energy rating', 'enum', null, ['values' => ['A', 'B', 'C', 'D', 'E', 'F', 'G']]],
            'furnished' => ['Furnished', 'boolean', null, null],
        ] as $slug => [$name, $valueType, $unit, $validation]) {
            PropertyFeatureDefinition::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'value_type' => $valueType,
                    'unit' => $unit,
                    'validation' => $validation,
                    'is_active' => true,
                ],
            );
        }

        $flags = [
            'agency_registration' => true,
            'customer_registration' => false,
            'agency_storefronts' => true,
            'team_management' => true,
            'listing_creation' => true,
            'media_storage_mb' => true,
            'likes' => true,
            'dislikes' => true,
            'comparisons' => false,
            'collaborative_collections' => false,
            'viewings' => true,
            'messaging' => true,
            'comments' => false,
            'ratings' => false,
            'newsletters' => false,
            'video' => false,
            'three_d' => false,
            'telegram_storage' => false,
            'mls' => false,
            'ai_search' => false,
            'ai_listing_writer' => false,
            'sponsored_listings' => false,
            'payments' => false,
        ];

        foreach ($flags as $key => $enabled) {
            FeatureFlag::query()->updateOrCreate(
                ['key' => $key],
                ['default_enabled' => $enabled],
            );
        }

        Setting::query()->updateOrCreate(
            ['namespace' => 'billing', 'key' => 'default_promotional_days'],
            ['value' => 365, 'is_secret' => false],
        );
    }
}
