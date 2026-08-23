<?php

namespace App\Http\Resources;

use App\Models\AgencyMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrincipalResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('memberships.agency', 'memberships.roles.permissions');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'memberships' => $this->memberships->map(fn (AgencyMember $membership) => [
                'id' => $membership->id,
                'status' => $membership->status,
                'job_title' => $membership->job_title,
                'agency' => (new AgencyResource($membership->agency))->resolve($request),
                'roles' => $membership->roles->pluck('slug')->sort()->values(),
                'permissions' => $membership->permissionNames(),
            ]),
        ];
    }
}
