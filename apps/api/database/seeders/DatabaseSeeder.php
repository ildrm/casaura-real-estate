<?php

namespace Database\Seeders;

use App\Models\FeatureFlag;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\PlanEntitlement;
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
            'agency_owner' => array_keys($permissions),
            'agency_manager' => [
                'agency.manage_profile', 'agency.manage_members', 'property.create',
                'property.publish', 'property.delete', 'lead.manage', 'analytics.view',
                'integration.configure', 'audit.view',
            ],
            'agent' => ['property.create', 'property.publish', 'lead.manage', 'analytics.view'],
            'content_manager' => ['agency.manage_profile', 'property.create'],
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

        foreach ([
            'agency_storefronts' => true,
            'team_management' => true,
            'listing_creation' => true,
            'messaging' => true,
            'viewings' => true,
        ] as $key => $value) {
            PlanEntitlement::query()->updateOrCreate(
                ['plan_id' => $plan->id, 'key' => $key],
                ['value' => $value, 'quota' => $key === 'team_management' ? 50 : null],
            );
        }

        $flags = [
            'agency_registration' => true,
            'customer_registration' => true,
            'agency_storefronts' => true,
            'team_management' => true,
            'listing_creation' => true,
            'likes' => true,
            'dislikes' => true,
            'comparisons' => true,
            'collaborative_collections' => true,
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
