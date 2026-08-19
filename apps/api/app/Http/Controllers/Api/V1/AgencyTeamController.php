<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\ApiException;
use App\Domain\Tenancy\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\AgencyMember;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AgencyTeamController extends Controller
{
    /** @var list<string> */
    private const ALLOWED_ROLES = ['agency_owner', 'agency_manager', 'agent', 'content_manager', 'agency_analyst'];

    public function index(TenantContext $tenant): JsonResponse
    {
        $members = AgencyMember::query()->where('agency_id', $tenant->id())->with(['user:id,name,email', 'roles:id,name,slug'])
            ->orderBy('created_at')->get()->map(fn (AgencyMember $member) => $this->projection($member));

        return response()->json(['data' => $members, 'meta' => ['quota' => $this->quota($tenant)]]);
    }

    public function store(Request $request, TenantContext $tenant, AuditRecorder $audit): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'], 'email' => ['required', 'email:rfc', 'max:254'],
            'job_title' => ['nullable', 'string', 'max:120'], 'role_slug' => ['required', 'string'],
        ]);
        $role = $this->role($validated['role_slug']);
        $email = mb_strtolower($validated['email']);

        $member = DB::transaction(function () use ($request, $tenant, $audit, $validated, $role, $email): AgencyMember {
            DB::table('agencies')->where('id', $tenant->id())->lockForUpdate()->firstOrFail();
            if ((int) DB::table('agency_members')->where('agency_id', $tenant->id())->count() >= $this->quota($tenant)) {
                throw new ApiException('TEAM_QUOTA_EXCEEDED', 'The active plan team quota has been reached.');
            }
            if (DB::table('agency_members')->join('users', 'users.id', '=', 'agency_members.user_id')
                ->where('agency_members.agency_id', $tenant->id())->whereRaw('LOWER(users.email) = ?', [$email])->exists()) {
                throw new ApiException('MEMBER_EXISTS', 'This email already belongs to the agency team.', 409);
            }
            $user = User::query()->firstOrCreate(['email' => $email], [
                'name' => $validated['name'], 'password' => Hash::make(Str::random(48)), 'status' => 'active',
            ]);
            $member = AgencyMember::query()->create([
                'agency_id' => $tenant->id(), 'user_id' => $user->id, 'status' => 'invited',
                'job_title' => $validated['job_title'] ?? null, 'invited_at' => now(),
            ]);
            $member->roles()->sync([$role->id]);
            $audit->recordEntity($request, 'agency.member_invited', 'agency_member', $member->id, null, [
                'role_slug' => $role->slug, 'status' => 'invited',
            ], $tenant->id());

            return $member->load('user', 'roles');
        });

        return response()->json(['data' => $this->projection($member)], 201);
    }

    public function update(Request $request, string $member, TenantContext $tenant, AuditRecorder $audit): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['invited', 'active', 'inactive'])],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:120'], 'role_slug' => ['sometimes', 'string'],
        ]);
        $record = AgencyMember::query()->where('agency_id', $tenant->id())->findOrFail($member);
        $role = isset($validated['role_slug']) ? $this->role($validated['role_slug']) : null;
        DB::transaction(function () use ($request, $tenant, $audit, $validated, $record, $role): void {
            $before = ['status' => $record->status, 'job_title' => $record->job_title, 'roles' => $record->roles()->pluck('slug')->all()];
            $record->fill(array_intersect_key($validated, array_flip(['status', 'job_title'])));
            if (($validated['status'] ?? null) === 'active' && ! $record->accepted_at) {
                $record->accepted_at = now();
            }
            $record->save();
            if ($role) {
                $record->roles()->sync([$role->id]);
            }
            $audit->recordEntity($request, 'agency.member_updated', 'agency_member', $record->id, $before, [
                'status' => $record->status, 'job_title' => $record->job_title, 'roles' => $record->roles()->pluck('slug')->all(),
            ], $tenant->id());
        });

        return response()->json(['data' => $this->projection($record->refresh()->load('user', 'roles'))]);
    }

    private function role(string $slug): Role
    {
        if (! in_array($slug, self::ALLOWED_ROLES, true)) {
            throw new ApiException('TEAM_ROLE_INVALID', 'Select an agency role that cannot grant platform access.');
        }

        return Role::query()->where('slug', $slug)->where('is_system', true)->firstOrFail();
    }

    private function quota(TenantContext $tenant): int
    {
        return (int) (DB::table('subscriptions')->join('plan_entitlements', 'plan_entitlements.plan_id', '=', 'subscriptions.plan_id')
            ->where('subscriptions.agency_id', $tenant->id())->where('plan_entitlements.key', 'team_management')->value('plan_entitlements.quota') ?? 50);
    }

    /** @return array<string, mixed> */
    private function projection(AgencyMember $member): array
    {
        $member->loadMissing('user', 'roles');

        return [
            'id' => $member->id, 'status' => $member->status, 'job_title' => $member->job_title,
            'user' => ['id' => $member->user->id, 'name' => $member->user->name, 'email' => $member->user->email],
            'roles' => $member->roles->map(fn (Role $role) => ['id' => $role->id, 'name' => $role->name, 'slug' => $role->slug]),
        ];
    }
}
