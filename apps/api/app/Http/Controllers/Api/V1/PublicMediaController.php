<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MediaDerivative;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicMediaController extends Controller
{
    public function __invoke(Request $request, string $media, string $kind): StreamedResponse
    {
        abort_unless(in_array($kind, ['thumbnail', 'display'], true), 404);
        $derivative = MediaDerivative::query()->where('media_id', $media)->where('kind', $kind)
            ->whereHas('media.listing', fn ($query) => $query->where('status', 'published')->whereNull('deleted_at'))
            ->firstOrFail();

        return Storage::disk('listing_media')->response(
            $derivative->storage_key,
            "property-{$media}-{$kind}.webp",
            ['Cache-Control' => 'public, max-age=86400, stale-while-revalidate=604800'],
        );
    }
}
