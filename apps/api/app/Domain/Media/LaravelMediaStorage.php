<?php

namespace App\Domain\Media;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class LaravelMediaStorage implements MediaStorage
{
    public function writeFromPath(string $key, string $localPath): int
    {
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Unable to read the staged media file.');
        }

        try {
            if (! Storage::disk('listing_media')->put($key, $stream)) {
                throw new RuntimeException('Unable to write the media object.');
            }
        } finally {
            fclose($stream);
        }

        return Storage::disk('listing_media')->size($key);
    }

    public function delete(array $keys): void
    {
        if ($keys !== [] && ! Storage::disk('listing_media')->delete($keys)) {
            throw new RuntimeException('Unable to remove one or more media objects.');
        }
    }

    public function move(string $from, string $to): void
    {
        if (! Storage::disk('listing_media')->move($from, $to)) {
            throw new RuntimeException('Unable to quarantine the media object.');
        }
    }
}
