<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class AgencyMemberController extends Controller
{
    public function index(TenantContext $context): JsonResponse
    {
        $members = $context->agency()->members()
            ->with(['user:id,name,email', 'roles:id,name,slug'])
            ->orderBy('created_at')
            ->get()
            ->map(fn ($member) => [
                'id' => $member->id,
                'status' => $member->status,
                'job_title' => $member->job_title,
                'user' => [
                    'id' => $member->user->id,
                    'name' => $member->user->name,
                    'email' => $member->user->email,
                ],
                'roles' => $member->roles->map(fn ($role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug,
                ]),
            ]);

        return response()->json(['data' => $members]);
    }
}
