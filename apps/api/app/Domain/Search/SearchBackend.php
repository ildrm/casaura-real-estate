<?php

namespace App\Domain\Search;

interface SearchBackend
{
    /** @param array<string, mixed> $document */
    public function upsert(array $document): void;

    public function delete(string $listingId): void;

    /** @param array<string, mixed> $criteria @return array{items: list<array<string, mixed>>, count: int, next_cursor: ?string} */
    public function search(array $criteria): array;

    public function reset(): void;
}
