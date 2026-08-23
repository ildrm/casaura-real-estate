<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\ApiException;
use App\Domain\Tenancy\AuditRecorder;
use App\Domain\Tenancy\FeatureResolver;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\AgencyMember;
use App\Models\Role;
use App\Models\User;
use App\Notifications\AgencyInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AgencyTeamController extends Controller
{
    /** @var list<string> */
    private const ALLOWED_ROLES = ['agency_owner', 'agency_manager', 'agent', 'content_manager', 'agency_analyst'];

    /** @var array<string, int> */
    private const ROLE_RANKS = [
        'agency_owner' => 50,
        'agency_manager' => 40,
        'content_manager' => 30,
        'agency_analyst' => 25,
        'agent' => 20,
    ];

    public function __construct(private readonly FeatureResolver $features) {}

    public function index(TenantContext $tenant): JsonResponse
    {
        $this->features->ensureEnabled('team_management', $tenant->agency());
        $members = AgencyMember::query()->where('agency_id', $tenant->id())->with(['user:id,name,email', 'roles:id,name,slug'])
            ->orderBy('created_at')->get()->map(fn (AgencyMember $member) => $this->projection($member));

        return response()->json(['data' => $members, 'meta' => ['quota' => $this->quota($tenant)]]);
    }

    public function store(Request $request, TenantContext $tenant, AuditRecorder $audit): JsonResponse
    {
        $this->features->ensureEnabled('team_management', $tenant->agency());
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'role_slug' => ['required', 'string'],
        ]);
        $role = $this->role($validated['role_slug']);
        $this->assertRoleAssignable($tenant, $role);
        $email = mb_strtolower($validated['email']);
        $token = Str::random(64);
        $inviter = $request->user();
        abort_unless($inviter instanceof User, 401);

        $member = DB::transaction(function () use ($request, $tenant, $audit, $validated, $role, $email, $token, $inviter): AgencyMember {
            DB::table('agencies')->where('id', $tenant->id())->lockForUpdate()->firstOrFail();
            $agency = $tenant->agency()->refresh();
            $this->features->ensureEnabled('team_management', $agency);
            $quota = $this->features->quota('team_management', $agency);
            $memberCount = DB::table('agency_members')->where('agency_id', $tenant->id())
                ->whereIn('status', ['invited', 'active'])->count();
            if ($quota !== null && $memberCount >= $quota) {
                throw new ApiException('TEAM_QUOTA_EXCEEDED', 'The active plan team quota has been reached.');
            }
            $existingMember = AgencyMember::query()->where('agency_id', $tenant->id())
                ->whereHas('user', fn ($query) => $query->whereRaw('LOWER(email) = ?', [$email]))
                ->with('user')->lockForUpdate()->first();
            if ($existingMember && $existingMember->status !== 'inactive') {
                throw new ApiException('MEMBER_EXISTS', 'This email already belongs to the agency team.', 409);
            }

            $user = $existingMember?->user ?? User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
            $userWasCreated = $existingMember
                ? ($existingMember->invited_user_was_created && $existingMember->accepted_at === null)
                : ! $user;
            if (! $user) {
                $user = User::query()->create([
                    'name' => $validated['name'],
                    'email' => $email,
                    'password' => Hash::make(Str::random(48)),
                    'status' => 'active',
                ]);
            }
            if ($user->status !== 'active') {
                throw new ApiException('INVITATION_ACCOUNT_UNAVAILABLE', 'This account cannot be invited.', 409);
            }

            $attributes = [
                'agency_id' => $tenant->id(),
                'user_id' => $user->id,
                'status' => 'invited',
                'job_title' => $validated['job_title'] ?? null,
                'invited_by_user_id' => $inviter->id,
                'invitation_token_hash' => hash('sha256', $token),
                'invitation_expires_at' => now()->addHours((int) config('identity.invitations.ttl_hours')),
                'invitation_cancelled_at' => null,
                'invited_user_was_created' => $userWasCreated,
                'invited_at' => now(),
                'accepted_at' => null,
                'is_public' => false,
                'public_position' => null,
            ];
            if ($existingMember) {
                $existingMember->forceFill($attributes)->save();
                $member = $existingMember;
            } else {
                $member = AgencyMember::query()->create($attributes);
            }
            $member->roles()->sync([$role->id]);
            $audit->recordEntity($request, 'agency.member_invited', 'agency_member', $member->id, null, [
                'role_slug' => $role->slug,
                'status' => 'invited',
                'invitation_expires_at' => $member->invitation_expires_at?->toISOString(),
            ], $tenant->id());

            return $member->load('agency', 'user', 'roles');
        });

        $this->sendInvitation($member, $token, $inviter);

        return response()->json(['data' => $this->projection($member)], 201);
    }

    public function update(Request $request, string $member, TenantContext $tenant, AuditRecorder $audit): JsonResponse
    {
        $this->features->ensureEnabled('team_management', $tenant->agency());
        $validated = $request->validate([
            'status' => ['sometimes', 'string'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'role_slug' => ['sometimes', 'string'],
            'is_public' => ['sometimes', 'boolean'],
            'public_position' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000'],
        ]);
        $role = isset($validated['role_slug']) ? $this->role($validated['role_slug']) : null;
        if ($role) {
            $this->assertRoleAssignable($tenant, $role);
        }
        if (isset($validated['status']) && $validated['status'] !== 'inactive') {
            throw ValidationException::withMessages([
                'status' => ['Invitations can only be activated through the invitation acceptance flow.'],
            ]);
        }

        $record = DB::transaction(function () use ($request, $tenant, $audit, $validated, $member, $role): AgencyMember {
            $agency = $tenant->agency()->newQuery()->whereKey($tenant->id())->lockForUpdate()->firstOrFail();
            $record = AgencyMember::query()->where('agency_id', $tenant->id())->with(['user', 'roles'])
                ->lockForUpdate()->findOrFail($member);
            $before = [
                'status' => $record->status,
                'job_title' => $record->job_title,
                'is_public' => $record->is_public,
                'public_position' => $record->public_position,
                'roles' => $record->roles->pluck('slug')->all(),
            ];
            $effectiveStatus = $validated['status'] ?? $record->status;
            $effectivePublic = array_key_exists('is_public', $validated) ? (bool) $validated['is_public'] : $record->is_public;
            if ($effectivePublic && $effectiveStatus !== 'active') {
                throw ValidationException::withMessages([
                    'is_public' => ['Only active team members can appear on the public storefront.'],
                ]);
            }

            $isOwner = $record->roles->contains('slug', 'agency_owner');
            $losesOwner = $isOwner && ($effectiveStatus !== 'active' || ($role && $role->slug !== 'agency_owner'));
            $replacementOwner = null;
            if ($losesOwner) {
                $replacementOwner = AgencyMember::query()
                    ->where('agency_id', $tenant->id())
                    ->where('status', 'active')
                    ->whereKeyNot($record->id)
                    ->whereHas('roles', fn ($query) => $query->where('slug', 'agency_owner'))
                    ->lockForUpdate()
                    ->oldest('created_at')
                    ->first();
                if (! $replacementOwner) {
                    throw new ApiException('LAST_OWNER_REQUIRED', 'An agency must retain at least one active owner.', 409);
                }
            }

            $record->fill(array_intersect_key($validated, array_flip([
                'status', 'job_title', 'is_public', 'public_position',
            ])));
            if ($record->status === 'inactive') {
                $record->is_public = false;
                $record->public_position = null;
            }
            $record->save();
            if ($role) {
                $record->roles()->sync([$role->id]);
            }
            if ($losesOwner && $agency->owner_user_id === $record->user_id) {
                $agency->update(['owner_user_id' => $replacementOwner->user_id]);
            }

            $audit->recordEntity($request, 'agency.member_updated', 'agency_member', $record->id, $before, [
                'status' => $record->status,
                'job_title' => $record->job_title,
                'is_public' => $record->is_public,
                'public_position' => $record->public_position,
                'roles' => $record->roles()->pluck('slug')->all(),
            ], $tenant->id());

            return $record->refresh()->load('user', 'roles');
        });

        return response()->json(['data' => $this->projection($record)]);
    }

    public function resendInvitation(
        Request $request,
        string $member,
        TenantContext $tenant,
        AuditRecorder $audit,
    ): JsonResponse {
        $this->features->ensureEnabled('team_management', $tenant->agency());
        $token = Str::random(64);
        $inviter = $request->user();
        abort_unless($inviter instanceof User, 401);

        $record = DB::transaction(function () use ($request, $member, $tenant, $audit, $token, $inviter): AgencyMember {
            $record = AgencyMember::query()->where('agency_id', $tenant->id())->with(['agency', 'user', 'roles'])
                ->lockForUpdate()->findOrFail($member);
            if ($record->status !== 'invited' || $record->invitation_cancelled_at) {
                throw new ApiException('INVITATION_NOT_PENDING', 'This membership does not have a pending invitation.', 409);
            }

            $beforeExpiry = $record->invitation_expires_at?->toISOString();
            $record->forceFill([
                'invited_by_user_id' => $inviter->id,
                'invitation_token_hash' => hash('sha256', $token),
                'invitation_expires_at' => now()->addHours((int) config('identity.invitations.ttl_hours')),
                'invitation_cancelled_at' => null,
                'invited_at' => now(),
            ])->save();
            $audit->recordEntity($request, 'agency.invitation_resent', 'agency_member', $record->id, [
                'invitation_expires_at' => $beforeExpiry,
            ], [
                'invitation_expires_at' => $record->invitation_expires_at?->toISOString(),
            ], $tenant->id());

            return $record;
        });

        $this->sendInvitation($record, $token, $inviter);

        return response()->json(['data' => $this->projection($record)]);
    }

    public function cancelInvitation(
        Request $request,
        string $member,
        TenantContext $tenant,
        AuditRecorder $audit,
    ): JsonResponse {
        $this->features->ensureEnabled('team_management', $tenant->agency());

        DB::transaction(function () use ($request, $member, $tenant, $audit): void {
            $record = AgencyMember::query()->where('agency_id', $tenant->id())->lockForUpdate()->findOrFail($member);
            if ($record->status !== 'invited') {
                throw new ApiException('INVITATION_NOT_PENDING', 'This membership does not have a pending invitation.', 409);
            }
            $record->forceFill([
                'status' => 'inactive',
                'invitation_token_hash' => null,
                'invitation_expires_at' => null,
                'invitation_cancelled_at' => now(),
                'is_public' => false,
                'public_position' => null,
            ])->save();
            $audit->recordEntity($request, 'agency.invitation_cancelled', 'agency_member', $record->id, [
                'status' => 'invited',
            ], [
                'status' => 'inactive',
            ], $tenant->id());
        });

        return response()->json(null, 204);
    }

    private function role(string $slug): Role
    {
        if (! in_array($slug, self::ALLOWED_ROLES, true)) {
            throw new ApiException('TEAM_ROLE_INVALID', 'Select an agency role that cannot grant platform access.');
        }

        return Role::query()->where('slug', $slug)->where('is_system', true)->firstOrFail();
    }

    private function assertRoleAssignable(TenantContext $tenant, Role $role): void
    {
        $actorRoles = $tenant->membership()->roles()->pluck('slug');
        $actorRank = $actorRoles->map(fn (string $slug): int => self::ROLE_RANKS[$slug] ?? 0)->max() ?? 0;
        $targetRank = self::ROLE_RANKS[$role->slug] ?? PHP_INT_MAX;
        if (($role->slug === 'agency_owner' && ! $actorRoles->contains('agency_owner')) || $targetRank > $actorRank) {
            throw new ApiException('TEAM_ROLE_ASSIGNMENT_DENIED', 'You cannot assign a role above your authority.', 403);
        }
    }

    private function sendInvitation(AgencyMember $member, string $token, User $inviter): void
    {
        try {
            $member->user->notify(new AgencyInvitation($token, $member->agency, $inviter));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function quota(TenantContext $tenant): ?int
    {
        return $this->features->quota('team_management', $tenant->agency());
    }

    /** @return array<string, mixed> */
    private function projection(AgencyMember $member): array
    {
        $member->loadMissing('user', 'roles');

        return [
            'id' => $member->id,
            'status' => $member->status,
            'job_title' => $member->job_title,
            'invitation_expires_at' => $member->invitation_expires_at?->toISOString(),
            'is_public' => $member->is_public,
            'public_position' => $member->public_position,
            'user' => ['id' => $member->user->id, 'name' => $member->user->name, 'email' => $member->user->email],
            'roles' => $member->roles->map(fn (Role $role) => ['id' => $role->id, 'name' => $role->name, 'slug' => $role->slug]),
        ];
    }
}
