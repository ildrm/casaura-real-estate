<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenancy\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\AgencyResource;
use App\Models\Agency;
use Illuminate\Http\Request;

class AgencyController extends Controller
{
    public function show(Agency $agency): AgencyResource
    {
        abort_unless($agency->status === 'active', 404);

        return new AgencyResource($agency);
    }

    public function current(TenantContext $context): AgencyResource
    {
        return new AgencyResource($context->agency()->load('subscription.plan.entitlements'));
    }

    public function update(
        Request $request,
        TenantContext $context,
        AuditRecorder $audit,
    ): AgencyResource {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:160'],
            'short_description' => ['sometimes', 'nullable', 'string', 'max:320'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'website' => ['sometimes', 'nullable', 'url:http,https', 'max:500'],
            'timezone' => ['sometimes', 'timezone:all'],
        ]);

        $agency = $context->agency();
        $before = $agency->only(array_keys($validated));
        $agency->fill($validated)->save();
        $audit->record(
            $request,
            'agency.profile_updated',
            $agency,
            $before,
            $agency->only(array_keys($validated)),
            $agency->id,
        );

        return new AgencyResource($agency->refresh());
    }
}
