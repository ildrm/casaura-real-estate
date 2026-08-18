<?php

namespace App\Domain\Search;

final class PublicLocationPolicy
{
    /** @return array{policy: string, latitude: ?float, longitude: ?float} */
    public function derive(?float $latitude, ?float $longitude, string $policy, string $seed): array
    {
        if ($latitude === null || $longitude === null || $policy === 'hidden') {
            return ['policy' => 'hidden', 'latitude' => null, 'longitude' => null];
        }
        if ($policy === 'exact') {
            return ['policy' => 'exact', 'latitude' => round($latitude, 7), 'longitude' => round($longitude, 7)];
        }

        $hash = hash('sha256', $seed.'|'.number_format($latitude, 7, '.', '').'|'.number_format($longitude, 7, '.', ''));
        $angle = (hexdec(substr($hash, 0, 8)) / 0xFFFFFFFF) * 2 * M_PI;
        $distanceMetres = 450 + (hexdec(substr($hash, 8, 8)) / 0xFFFFFFFF) * 400;
        $latitudeOffset = ($distanceMetres / 111320) * cos($angle);
        $longitudeScale = max(cos(deg2rad($latitude)), 0.01);
        $longitudeOffset = ($distanceMetres / (111320 * $longitudeScale)) * sin($angle);

        return [
            'policy' => 'approximate',
            'latitude' => round(max(-90, min(90, $latitude + $latitudeOffset)), 7),
            'longitude' => round($this->wrapLongitude($longitude + $longitudeOffset), 7),
        ];
    }

    private function wrapLongitude(float $longitude): float
    {
        return fmod($longitude + 540, 360) - 180;
    }
}
