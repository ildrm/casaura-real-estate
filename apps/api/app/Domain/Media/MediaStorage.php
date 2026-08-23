<?php

namespace App\Domain\Media;

interface MediaStorage
{
    public function writeFromPath(string $key, string $localPath): int;

    /** @param list<string> $keys */
    public function delete(array $keys): void;

    public function move(string $from, string $to): void;

    public function exists(string $key): bool;
}
