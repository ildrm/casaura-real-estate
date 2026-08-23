<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\ApiException;
use App\Domain\Tenancy\AuditRecorder;
use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminRoleController extends Controller
{
    /** @var list<string> */
    private const AGENCY_PERMISSION_GROUPS = ['agency', 'property', 'listing', 'lead', 'analytics', 'billing', 'integration', 'audit'];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string'],
        ]);
        $page = Role::query()->with('permissions:id,name,group')->orderBy('scope')->orderBy('name')->orderBy('id')
            ->cursorPaginate($validated['limit'] ?? 50);

        return response()->json(['data' => [
            'permissions' => Permission::query()->orderBy('group')->orderBy('name')->get(['id', 'name', 'group', 'description']),
            'roles' => collect($page->items())->map(fn (Role $role) => $this->projection($role)),
        ], 'meta' => ['next_cursor' => $page->nextCursor()?->encode()]]);
    }

    public function store(Request $request, AuditRecorder $audit): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'], 'slug' => ['required', 'alpha_dash', 'max:120'],
            'scope' => ['required', Rule::in(['agency', 'platform'])], 'permissions' => ['required', 'array', 'max:100'],
            'permissions.*' => ['string', 'distinct'],
        ]);
        if (Role::query()->where('scope', $validated['scope'])->where('slug', $validated['slug'])->exists()) {
            throw new ApiException('ROLE_EXISTS', 'A role with this scope and slug already exists.', 409);
        }
        $permissions = $this->permissions($validated['permissions'], $validated['scope']);
        $role = DB::transaction(function () use ($request, $audit, $validated, $permissions): Role {
            $role = Role::query()->create([
                'name' => $validated['name'], 'slug' => $validated['slug'], 'scope' => $validated['scope'], 'is_system' => false,
            ]);
            $role->permissions()->sync($permissions->pluck('id'));
            $audit->recordEntity($request, 'role.created', 'role', $role->id, null, [
                'scope' => $role->scope, 'slug' => $role->slug, 'permissions' => $permissions->pluck('name')->all(),
            ]);

            return $role->load('permissions');
        });

        return response()->json(['data' => $this->projection($role)], 201);
    }

    public function update(Request $request, string $role, AuditRecorder $audit): JsonResponse
    {
        $record = Role::query()->with('permissions')->findOrFail($role);
        $this->mutable($record);
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'], 'permissions' => ['sometimes', 'array', 'max:100'],
            'permissions.*' => ['string', 'distinct'],
        ]);
        $permissions = isset($validated['permissions']) ? $this->permissions($validated['permissions'], $record->scope) : null;
        DB::transaction(function () use ($request, $audit, $validated, $permissions, $record): void {
            $before = ['name' => $record->name, 'permissions' => $record->permissions->pluck('name')->all()];
            if (isset($validated['name'])) {
                $record->name = $validated['name'];
                $record->save();
            }
            if ($permissions !== null) {
                $record->permissions()->sync($permissions->pluck('id'));
            }
            $audit->recordEntity($request, 'role.updated', 'role', $record->id, $before, [
                'name' => $record->name, 'permissions' => $record->permissions()->pluck('name')->all(),
            ]);
        });

        return response()->json(['data' => $this->projection($record->refresh()->load('permissions'))]);
    }

    public function destroy(Request $request, string $role, AuditRecorder $audit): JsonResponse
    {
        $record = Role::query()->with('permissions')->findOrFail($role);
        $this->mutable($record);
        DB::transaction(function () use ($request, $audit, $record): void {
            $audit->recordEntity($request, 'role.deleted', 'role', $record->id, [
                'name' => $record->name, 'scope' => $record->scope, 'permissions' => $record->permissions->pluck('name')->all(),
            ]);
            $record->delete();
        });

        return response()->json(status: 204);
    }

    private function mutable(Role $role): void
    {
        if ($role->is_system) {
            throw new ApiException('SYSTEM_ROLE_IMMUTABLE', 'System role templates cannot be changed.', 409);
        }
    }

    /** @param list<string> $names @return \Illuminate\Database\Eloquent\Collection<int, Permission> */
    private function permissions(array $names, string $scope): Collection
    {
        $permissions = Permission::query()->whereIn('name', $names)->get();
        if ($permissions->count() !== count($names) || ($scope === 'agency' && $permissions->contains(fn (Permission $permission) => ! in_array($permission->group, self::AGENCY_PERMISSION_GROUPS, true)))) {
            throw new ApiException('ROLE_PERMISSION_INVALID', 'One or more permissions are unknown or unsafe for this role scope.');
        }

        return $permissions;
    }

    /** @return array<string, mixed> */
    private function projection(Role $role): array
    {
        $role->loadMissing('permissions');

        return [
            'id' => $role->id, 'name' => $role->name, 'slug' => $role->slug, 'scope' => $role->scope,
            'system' => (bool) $role->is_system, 'permissions' => $role->permissions->pluck('name')->sort()->values(),
        ];
    }
}
