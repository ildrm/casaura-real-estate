<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\ApiException;
use App\Domain\Identity\IdentityPolicy;
use App\Domain\Tenancy\AuditRecorder;
use App\Domain\Tenancy\FeatureResolver;
use App\Http\Controllers\Controller;
use App\Http\Resources\PrincipalResource;
use App\Models\Agency;
use App\Models\AgencyBranch;
use App\Models\AgencyMember;
use App\Models\ConsentRecord;
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
use Laravel\Sanctum\TransientToken;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly FeatureResolver $features,
        private readonly IdentityPolicy $identity,
    ) {}

    public function register(Request $request): JsonResponse
    {
        $this->features->ensureEnabled('customer_registration');
        $validated = $this->validateAccount($request);

        $user = DB::transaction(function () use ($validated): User {
            $user = User::query()->create($this->userAttributes($validated));
            $this->recordConsent($user, null, 'customer_registration', $validated);

            return $user;
        });
        $this->establishSession($request, $user);
        $this->audit->record($request, 'user.registered', $user, after: ['email' => $user->email]);
        $this->sendVerification($user);

        return (new PrincipalResource($user))->response()->setStatusCode(201);
    }

    public function registerAgency(Request $request): JsonResponse
    {
        $this->features->ensureEnabled('agency_registration');
        $validated = array_merge($this->validateAccount($request), $request->validate([
            'agency_name' => ['required', 'string', 'min:2', 'max:160'],
            'timezone' => ['nullable', 'timezone:all'],
        ]));

        [$user, $agency] = DB::transaction(function () use ($validated): array {
            $user = User::query()->create($this->userAttributes($validated));

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

            $this->recordConsent($user, $agency, 'agency_registration', $validated);

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
        $this->sendVerification($user);

        return (new PrincipalResource($user->fresh()))->response()->setStatusCode(201);
    }

    public function login(Request $request): JsonResponse|PrincipalResource
    {
        $credentials = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'password' => ['required', 'string'],
        ]);

        $guard = Auth::guard('web');
        if (! $guard->attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => ['The provided credentials are incorrect.']]);
        }

        $user = $guard->user();
        if (! $user || $user->status !== 'active') {
            $guard->logout();
            throw ValidationException::withMessages(['email' => ['This account is not available.']]);
        }

        if ($request->hasSession()) {
            $request->session()->regenerate();
            $request->session()->put('identity.security_version', $user->security_version);
        }

        if (! $user->hasVerifiedEmail()) {
            $this->audit->record($request, 'user.logged_in', $user);

            return new PrincipalResource($user);
        }

        if ($this->identity->requiresMfa($user)) {
            if (! $request->hasSession()) {
                $guard->logout();
                throw new ApiException(
                    'IDENTITY_SESSION_REQUIRED',
                    'Use the first-party secure session flow to complete authentication.',
                    400,
                );
            }
            if (! $user->mfa_confirmed_at || ! $user->mfa_secret) {
                return response()->json(['error' => [
                    'code' => 'MFA_SETUP_REQUIRED',
                    'message' => 'Set up multi-factor authentication before continuing.',
                    'fields' => (object) [],
                    'request_id' => $request->attributes->get('request_id'),
                ]], 202);
            }

            $remember = $request->boolean('remember');
            $guard->logout();
            $request->session()->regenerate();
            $request->session()->put([
                'identity.pending_user_id' => $user->id,
                'identity.pending_expires_at' => now()->addSeconds((int) config('identity.mfa.challenge_ttl_seconds', 300))->timestamp,
                'identity.pending_remember' => $remember,
            ]);

            return response()->json(['error' => [
                'code' => 'MFA_REQUIRED',
                'message' => 'Enter a multi-factor authentication code.',
                'fields' => (object) [],
                'request_id' => $request->attributes->get('request_id'),
            ]], 202);
        }

        $this->audit->record($request, 'user.logged_in', $user);

        return new PrincipalResource($user);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->audit->record($request, 'user.logged_out', $user);

        if ($user?->currentAccessToken() && ! $user->currentAccessToken() instanceof TransientToken) {
            $user->currentAccessToken()->delete();
        }

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(null, 204);
    }

    /** @return array{name: string, email: string, password: string, consent: bool, consent_version: string, timezone?: string} */
    private function validateAccount(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:254', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()],
            'consent' => ['required', 'accepted'],
            'consent_version' => ['required', 'string', 'in:'.config('identity.legal.version')],
        ]);
        $validated['email'] = mb_strtolower($validated['email']);

        return $validated;
    }

    private function establishSession(Request $request, User $user): void
    {
        if ($request->hasSession()) {
            // Database defaults are not guaranteed to be hydrated on a newly
            // inserted model. Refresh before asserting the security version so
            // the first authenticated request cannot revoke a valid new session.
            $user->refresh();
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
            $request->session()->put('identity.security_version', $user->security_version);
        }
    }

    /** @param array<string, mixed> $validated @return array<string, mixed> */
    private function userAttributes(array $validated): array
    {
        return [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'timezone' => $validated['timezone'] ?? 'UTC',
        ];
    }

    /** @param array<string, mixed> $validated */
    private function recordConsent(User $user, ?Agency $agency, string $purpose, array $validated): void
    {
        $legalText = (string) config('identity.legal.text');
        ConsentRecord::query()->create([
            'user_id' => $user->id,
            'agency_id' => $agency?->id,
            'purpose' => $purpose,
            'document_version' => $validated['consent_version'],
            'source' => 'web_registration',
            'legal_text' => $legalText,
            'legal_text_sha256' => hash('sha256', $legalText),
            'consented_at' => now(),
        ]);
    }

    private function sendVerification(User $user): void
    {
        try {
            $user->sendEmailVerificationNotification();
        } catch (\Throwable $exception) {
            report($exception);
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
