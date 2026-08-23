<?php

namespace App\Domain\Listings;

use App\Models\Listing;

final class ListingQualityCalculator
{
    /** @return array{score: int, ready_for_review: bool, checklist: list<array{key: string, complete: bool, message: string}>} */
    public function calculate(Listing $listing): array
    {
        $listing->loadMissing([
            'property.propertyType',
            'property.address',
            'property.featureValues',
            'property.amenities',
        ]);

        $property = $listing->property;
        $address = $property->address;
        $mediaCount = $listing->media()->count();

        $checks = [
            'basics' => [
                $property->property_type_id !== null
                    && in_array($listing->intent, ['sale', 'rent'], true)
                    && $listing->price_amount_minor !== null
                    && filled($listing->title)
                    && $property->bedrooms !== null
                    && $property->bathrooms !== null
                    && $property->interior_area_sqm !== null,
                25,
                'Complete price, title, bedrooms, bathrooms, and interior area.',
            ],
            'location' => [
                $address !== null
                    && filled($address->line_1)
                    && filled($address->locality)
                    && filled($address->region)
                    && filled($address->postal_code)
                    && filled($address->country_code),
                20,
                'Add the complete property location.',
            ],
            'description' => [
                mb_strlen(trim((string) $listing->description)) >= 80,
                15,
                'Write a description of at least 80 characters.',
            ],
            'features' => [
                $property->featureValues->isNotEmpty() && $property->amenities->isNotEmpty(),
                10,
                'Add at least one feature and one amenity.',
            ],
            'media' => [
                $mediaCount >= 5,
                30,
                'Add at least 5 photos.',
            ],
        ];

        $score = 0;
        $checklist = [];
        foreach ($checks as $key => [$complete, $weight, $message]) {
            if ($complete) {
                $score += $weight;
            }
            $checklist[] = [
                'key' => $key,
                'complete' => $complete,
                'message' => $complete ? $this->completeMessage($key) : $message,
            ];
        }

        return [
            'score' => $score,
            'ready_for_review' => $score >= 80 && collect($checklist)->every('complete'),
            'checklist' => $checklist,
        ];
    }

    private function completeMessage(string $key): string
    {
        return match ($key) {
            'basics' => 'Basic listing facts complete.',
            'location' => 'Location complete.',
            'description' => 'Description complete.',
            'features' => 'Features and amenities added.',
            'media' => 'Photo minimum reached.',
        };
    }
}
