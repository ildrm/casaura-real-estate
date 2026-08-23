<?php

namespace App\Domain\Integrations;

use App\Models\ProviderConnection;

interface RealEstateDataProviderClient
{
    /** @return array{resources: list<array{name: string, fields: list<array{name: string, type: string}>}>} */
    public function metadata(ProviderConnection $connection): array;

    /** @return iterable<array<string, mixed>> */
    public function records(ProviderConnection $connection, string $resource, ?string $cursor = null): iterable;
}
