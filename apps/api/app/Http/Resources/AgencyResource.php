<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgencyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'email' => $this->when($request->attributes->get('agency')?->is($this->resource), $this->email),
            'phone' => $this->phone,
            'website' => $this->website,
            'short_description' => $this->short_description,
            'description' => $this->description,
            'timezone' => $this->timezone,
            'verification_status' => $this->verification_status,
            'status' => $this->status,
        ];
    }
}
