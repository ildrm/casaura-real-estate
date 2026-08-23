<?php

namespace App\Domain\Media;

use App\Domain\Listings\ListingException;
use App\Domain\Listings\ListingManager;
use App\Domain\Tenancy\FeatureResolver;
use App\Domain\Tenancy\TenantContext;
use App\Models\Agency;
use App\Models\Listing;
use App\Models\Media;
use App\Models\MediaDerivative;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class ListingMediaManager
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly MediaStorage $storage,
        private readonly ImageDerivativeGenerator $images,
        private readonly MediaMalwareScanner $scanner,
        private readonly ListingManager $listings,
        private readonly FeatureResolver $features,
    ) {}

    /** @return array{media: Media, created: bool} */
    public function upload(Request $request, Listing $listing, UploadedFile $file, string $idempotencyKey, ?string $altText): array
    {
        $this->features->ensureEnabled('media_storage_mb', $this->tenant->agency());
        $existing = Media::withTrashed()->where('listing_id', $listing->id)
            ->where('idempotency_key', $idempotencyKey)->first();
        if ($existing?->trashed()) {
            throw new ListingException('UPLOAD_KEY_RETIRED', 'This upload key belongs to removed media.', 409);
        }
        if ($existing) {
            return ['media' => $existing->load('derivatives'), 'created' => false];
        }
        if ($listing->media()->count() >= 30) {
            throw new ListingException('MEDIA_QUOTA_EXCEEDED', 'This listing already has the maximum of 30 images.');
        }

        $sourcePath = $file->getRealPath();
        if (! is_string($sourcePath)) {
            throw new ListingException('MEDIA_INVALID', 'The upload could not be read.');
        }
        $this->scanner->assertClean($sourcePath);
        $details = $this->images->inspect($sourcePath);
        $mediaId = (string) Str::uuid();
        $extension = match ($details['mime_type']) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        };
        $prefix = "agencies/{$this->tenant->id()}/listings/{$listing->id}/{$mediaId}";
        $originalKey = "{$prefix}/original.{$extension}";
        $written = [];
        $temporary = [];

        try {
            $originalBytes = $this->storage->writeFromPath($originalKey, $sourcePath);
            $written[] = $originalKey;
            $derivativeData = [];
            foreach (['thumbnail' => 480, 'display' => 1600] as $kind => $maxWidth) {
                $derivative = $this->images->generateWebp($sourcePath, $details['mime_type'], $maxWidth);
                $temporary[] = $derivative['path'];
                $key = "{$prefix}/{$kind}.webp";
                $bytes = $this->storage->writeFromPath($key, $derivative['path']);
                $written[] = $key;
                $derivativeData[] = [...$derivative, 'kind' => $kind, 'storage_key' => $key, 'byte_size' => $bytes];
            }

            $media = DB::transaction(function () use (
                $request, $listing, $file, $idempotencyKey, $altText, $mediaId, $details,
                $originalKey, $originalBytes, $sourcePath, $derivativeData,
            ): Media {
                $agency = Agency::query()->whereKey($this->tenant->id())->lockForUpdate()->firstOrFail();
                $this->features->ensureEnabled('media_storage_mb', $agency);
                $quotaMb = $this->features->quota('media_storage_mb', $agency);
                if ($quotaMb !== null) {
                    $originalUsage = (int) Media::query()->where('agency_id', $agency->id)->sum('byte_size');
                    $derivativeUsage = (int) DB::table('media_derivatives')
                        ->join('media', 'media.id', '=', 'media_derivatives.media_id')
                        ->where('media.agency_id', $agency->id)
                        ->whereNull('media.deleted_at')
                        ->sum('media_derivatives.byte_size');
                    $proposedUsage = $originalBytes + array_sum(array_column($derivativeData, 'byte_size'));
                    if ($originalUsage + $derivativeUsage + $proposedUsage > $quotaMb * 1024 * 1024) {
                        throw new ListingException(
                            'MEDIA_STORAGE_QUOTA_EXCEEDED',
                            'The active plan media storage quota has been reached.',
                        );
                    }
                }

                $position = (int) $listing->media()->max('position') + 1;
                $media = Media::query()->create([
                    'id' => $mediaId,
                    'agency_id' => $this->tenant->id(),
                    'listing_id' => $listing->id,
                    'idempotency_key' => $idempotencyKey,
                    'original_name' => Str::limit($file->getClientOriginalName(), 255, ''),
                    'mime_type' => $details['mime_type'],
                    'byte_size' => $originalBytes,
                    'width' => $details['width'],
                    'height' => $details['height'],
                    'position' => $position,
                    'checksum_sha256' => hash_file('sha256', $sourcePath),
                    'storage_key' => $originalKey,
                    'alt_text' => $altText,
                ]);
                foreach ($derivativeData as $derivative) {
                    MediaDerivative::query()->create([
                        'media_id' => $media->id,
                        'kind' => $derivative['kind'],
                        'storage_key' => $derivative['storage_key'],
                        'mime_type' => $derivative['mime_type'],
                        'byte_size' => $derivative['byte_size'],
                        'width' => $derivative['width'],
                        'height' => $derivative['height'],
                    ]);
                }
                $this->listings->touchForMedia($request, $listing, 'listing.media_uploaded');

                return $media->load('derivatives');
            });
        } catch (ListingException $exception) {
            $this->deleteQuietly($written);
            throw $exception;
        } catch (Throwable $exception) {
            $this->deleteQuietly($written);
            report($exception);
            throw new ListingException('MEDIA_STORAGE_UNAVAILABLE', 'Media storage is temporarily unavailable.', 503);
        } finally {
            foreach ($temporary as $path) {
                @unlink($path);
            }
        }

        return ['media' => $media, 'created' => true];
    }

    /** @param list<string> $mediaIds @return list<Media> */
    public function reorder(Request $request, Listing $listing, array $mediaIds): array
    {
        return DB::transaction(function () use ($request, $listing, $mediaIds): array {
            $owned = $listing->media()->whereIn('id', $mediaIds)->get()->keyBy('id');
            if ($owned->count() !== count($mediaIds) || $listing->media()->count() !== count($mediaIds)) {
                throw new ListingException('MEDIA_ORDER_INVALID', 'The media order must contain every active image exactly once.');
            }
            foreach ($mediaIds as $position => $id) {
                $owned->get($id)->update(['position' => $position + 1]);
            }
            $this->listings->touchForMedia($request, $listing, 'listing.media_reordered');

            return $listing->media()->with('derivatives')->get()->all();
        });
    }

    public function delete(Request $request, Listing $listing, Media $media): void
    {
        DB::transaction(function () use ($request, $listing, $media): void {
            $keys = collect([$media->storage_key])->merge($media->derivatives->pluck('storage_key'));
            foreach ($keys as $key) {
                $quarantine = "quarantine/{$this->tenant->id()}/{$media->id}/".basename($key);
                $this->storage->move($key, $quarantine);
                if ($key === $media->storage_key) {
                    $media->storage_key = $quarantine;
                } else {
                    $media->derivatives->firstWhere('storage_key', $key)?->update(['storage_key' => $quarantine]);
                }
            }
            $media->save();
            $media->delete();
            $this->listings->touchForMedia($request, $listing, 'listing.media_deleted');
        });
    }

    /** @param list<string> $keys */
    private function deleteQuietly(array $keys): void
    {
        try {
            $this->storage->delete($keys);
        } catch (Throwable) {
            // A failed cleanup is reported by the original storage failure and can be reconciled by object inventory.
        }
    }
}
