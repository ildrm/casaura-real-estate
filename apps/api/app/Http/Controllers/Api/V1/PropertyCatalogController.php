<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Amenity;
use App\Models\PropertyFeatureDefinition;
use App\Models\PropertyType;
use Illuminate\Http\JsonResponse;

class PropertyCatalogController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => [
            'property_types' => PropertyType::query()->where('is_active', true)->orderBy('name')->get(['slug', 'name', 'category']),
            'amenities' => Amenity::query()->where('is_active', true)->orderBy('group')->orderBy('name')->get(['slug', 'name', 'group']),
            'feature_definitions' => PropertyFeatureDefinition::query()->where('is_active', true)->orderBy('name')
                ->get(['slug', 'name', 'value_type', 'unit', 'validation']),
        ]]);
    }
}
