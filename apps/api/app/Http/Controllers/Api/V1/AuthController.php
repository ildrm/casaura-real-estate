<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenancy\AuditRecorder;
use App\Http\Controllers\Controller;
use App\Http\Resources\PrincipalResource;
use App\Models\Agency;
use App\Models\AgencyBranch;
use App\Models\AgencyMember;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function register(Request $request): JsonResponse
    {
        $validated = $this->validateAccount($request);

        $user = User::query()->create($validated);
        $this->establishSession($request, $user);
        $this->audit->record($request, 'user.registered', $user, after: ['email' => $user->email]);

        return (new PrincipalResource($user))->response()->setStatusCode(201);
    }

    public function registerAgency(Request $request): JsonResponse
    {
        $validated = array_merge($this->validateAccount($request), $request->validate([
            'agency_name' => ['required', 'string', 'min:2', 'max:160'],
            'timezone' => ['nullable', 'timezone:all'],
        ]));

        [$user, $agency] = DB::transaction(function () use ($validated): array {
            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'timezone' => $validated['timezone'] ?? 'UTC',
            ]);

            $agency = Agency::query()->create([
                'owner_user_id' => $user->id,
                'name' => $validated['agency_name'],
                'slug' => $this->uniqueAgencySlug($validated['agency_name']),
                'email' => $validated['email'],
                'timezone' => $validated['timezone'] ?? 'UTC',
            ]);

            AgencyBranch::query()->create([
                'agency_id' => $agency->id,
                'name' => 'Main office',
                'slug' => 'main-office',
                'is_primary' => true,
                'email' => $validated['email'],
                'timezone' => $agency->timezone,
            ]);

            $member = AgencyMember::query()->create([
                'agency_id' => $agency->id,
                'user_id' => $user->id,
                'status' => 'active',
                'job_title' => 'Agency owner',
                'accepted_at' => now(),
            ]);

            $ownerRole = Role::query()->where('scope', 'platform')->where('slug', 'agency_owner')->firstOrFail();
            $member->roles()->attach($ownerRole);

            $plan = Plan::query()->where('slug', 'launch')->where('is_active', true)->firstOrFail();
            $promotionDays = max(0, (int) Setting::read('billing', 'default_promotional_days', 0));

            Subscription::query()->create([
                'agency_id' => $agency->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'billing_status' => 'not_required',
                'promotional_starts_at' => $promotionDays > 0 ? now() : null,
                'promotional_ends_at' => $promotionDays > 0 ? now()->addDays($promotionDays) : null,
                'free_until' => $promotionDays > 0 ? now()->addDays($promotionDays) : null,
            ]);

            return [$user, $agency];
        });

        $this->establishSession($request, $user);
        $this->audit->record(
            $request,
            'agency.registered',
            $agency,
            after: ['name' => $agency->name, 'verification_status' => $agency->verification_status],
            agencyId: $agency->id,
        );

        return (new PrincipalResource($user->fresh()))->response()->setStatusCode(201);
    }

    public function login(Request $request): PrincipalResource
    {
        $credentials = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => ['The provided credentials are incorrect.']]);
        }

        $user = $request->user();
        if (! $user || $user->status !== 'active') {
            Auth::logout();
            throw ValidationException::withMessages(['email' => ['This account is not available.']]);
        }

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $this->audit->record($request, 'user.logged_in', $user);

        return new PrincipalResource($user);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->audit->record($request, 'user.logged_out', $user);

        if ($user?->currentAccessToken()) {
            $user->currentAccessToken()->delete();
        }

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(null, 204);
    }

    /** @return array{name: string, email: string, password: string} */
    private function validateAccount(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:254', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()],
        ]);
    }

    private function establishSession(Request $request, User $user): void
    {
        if ($request->hasSession()) {
            Auth::login($user);
            $request->session()->regenerate();
        }
    }

    private function uniqueAgencySlug(string $name): string
    {
        $base = Str::slug($name) ?: 'agency';
        $slug = $base;
        $counter = 2;

        while (Agency::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$counter++;
        }

        return $slug;
    }
}
