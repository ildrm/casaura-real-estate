<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MediaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'byte_size' => $this->byte_size,
            'width' => $this->width,
            'height' => $this->height,
            'position' => $this->position,
            'alt_text' => $this->alt_text,
            'derivatives' => $this->whenLoaded('derivatives', fn () => $this->derivatives->map(fn ($derivative) => [
                'kind' => $derivative->kind,
                'mime_type' => $derivative->mime_type,
                'byte_size' => $derivative->byte_size,
                'width' => $derivative->width,
                'height' => $derivative->height,
            ])),
            'created_at' => $this->created_at,
        ];
    }
}
