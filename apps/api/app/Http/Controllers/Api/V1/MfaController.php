<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\ApiException;
use App\Domain\Identity\IdentityPolicy;
use App\Domain\Identity\Totp;
use App\Domain\Tenancy\AuditRecorder;
use App\Http\Controllers\Controller;
use App\Http\Resources\PrincipalResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class MfaController extends Controller
{
    public function __construct(
        private readonly Totp $totp,
        private readonly IdentityPolicy $identity,
        private readonly AuditRecorder $audit,
    ) {}

    public function setup(Request $request): JsonResponse
    {
        $validated = $request->validate(['password' => ['required', 'string']]);
        $user = $request->user();
        if (! $user instanceof User || ! Hash::check($validated['password'], $user->password)) {
            throw new ApiException('IDENTITY_PASSWORD_INVALID', 'The current password is incorrect.');
        }

        $secret = $this->totp->generateSecret();
        $user->forceFill([
            'mfa_secret' => $secret,
            'mfa_confirmed_at' => null,
            'mfa_last_used_timestep' => null,
            'mfa_recovery_codes' => null,
        ])->save();
        $this->audit->record($request, 'user.mfa_setup_started', $user);

        return response()->json(['data' => [
            'secret' => $secret,
            'provisioning_uri' => $this->totp->provisioningUri($secret, $user->email),
        ]]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/']]);
        $actor = $request->user();
        if (! $actor instanceof User) {
            abort(401);
        }

        $recoveryCodes = DB::transaction(function () use ($actor, $validated): array {
            $user = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if ($user->mfa_confirmed_at) {
                throw new ApiException('MFA_ALREADY_ENABLED', 'Multi-factor authentication is already enabled.', 409);
            }
            if (! $user->mfa_secret) {
                throw new ApiException('MFA_SETUP_REQUIRED', 'Start multi-factor authentication setup first.', 409);
            }
            $timestep = $this->totp->matchingTimestep($user->mfa_secret, $validated['code']);
            if ($timestep === null) {
                throw new ApiException('MFA_CODE_INVALID', 'The multi-factor authentication code is invalid.');
            }

            $codes = $this->generateRecoveryCodes();
            $user->forceFill([
                'mfa_confirmed_at' => now(),
                'mfa_last_used_timestep' => $timestep,
                'mfa_recovery_codes' => array_map(fn (string $code) => hash('sha256', $this->normalizeRecoveryCode($code)), $codes),
            ])->save();

            return $codes;
        });

        if ($request->hasSession()) {
            $request->session()->put('identity.mfa_security_version', $actor->security_version);
        }
        $this->audit->record($request, 'user.mfa_enabled', $actor);

        return response()->json(['data' => ['recovery_codes' => $recoveryCodes]]);
    }

    public function challenge(Request $request): JsonResponse|PrincipalResource
    {
        $validated = $request->validate(['code' => ['required', 'string', 'min:6', 'max:32']]);
        $pendingUserId = $request->session()->get('identity.pending_user_id');
        $expiresAt = (int) $request->session()->get('identity.pending_expires_at', 0);
        if (! is_string($pendingUserId) || $pendingUserId === '' || $expiresAt < now()->timestamp) {
            $this->forgetPending($request);
            throw new ApiException('MFA_CHALLENGE_EXPIRED', 'The authentication challenge has expired.', 401);
        }

        [$user, $usedRecoveryCode] = DB::transaction(function () use ($pendingUserId, $validated): array {
            $user = User::query()->whereKey($pendingUserId)->lockForUpdate()->first();
            if (! $user || $user->status !== 'active' || ! $user->mfa_confirmed_at || ! $user->mfa_secret) {
                throw new ApiException('MFA_CHALLENGE_INVALID', 'The authentication challenge is not available.', 401);
            }

            $code = trim($validated['code']);
            $timestep = $this->totp->matchingTimestep($user->mfa_secret, $code);
            $usedRecoveryCode = false;
            if ($timestep !== null && ($user->mfa_last_used_timestep === null || $timestep > $user->mfa_last_used_timestep)) {
                $user->mfa_last_used_timestep = $timestep;
            } else {
                $normalized = $this->normalizeRecoveryCode($code);
                $hash = hash('sha256', $normalized);
                $recoveryCodes = $user->mfa_recovery_codes ?? [];
                $match = null;
                foreach ($recoveryCodes as $index => $storedHash) {
                    if (hash_equals((string) $storedHash, $hash)) {
                        $match = $index;
                        break;
                    }
                }
                if ($match === null) {
                    throw new ApiException('MFA_CODE_INVALID', 'The multi-factor authentication code is invalid.');
                }
                unset($recoveryCodes[$match]);
                $user->mfa_recovery_codes = array_values($recoveryCodes);
                $usedRecoveryCode = true;
            }
            $user->save();

            return [$user, $usedRecoveryCode];
        });

        $remember = (bool) $request->session()->get('identity.pending_remember', false);
        $this->forgetPending($request);
        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();
        $request->session()->put([
            'identity.security_version' => $user->security_version,
            'identity.mfa_security_version' => $user->security_version,
        ]);
        $this->audit->record($request, $usedRecoveryCode ? 'user.mfa_recovery_used' : 'user.mfa_challenge_completed', $user);

        return new PrincipalResource($user);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);
        $user = $request->user();
        if (! $user instanceof User || ! Hash::check($validated['password'], $user->password)) {
            throw new ApiException('IDENTITY_PASSWORD_INVALID', 'The current password is incorrect.');
        }
        if ($this->identity->requiresMfa($user)) {
            throw new ApiException('MFA_REQUIRED_ROLE', 'Multi-factor authentication cannot be disabled for this role.', 409);
        }
        if (! $user->mfa_secret || $this->totp->matchingTimestep($user->mfa_secret, $validated['code']) === null) {
            throw new ApiException('MFA_CODE_INVALID', 'The multi-factor authentication code is invalid.');
        }

        $user->forceFill([
            'mfa_secret' => null,
            'mfa_confirmed_at' => null,
            'mfa_last_used_timestep' => null,
            'mfa_recovery_codes' => null,
            'security_version' => $user->security_version + 1,
        ])->save();
        $user->tokens()->delete();
        DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
        $request->session()->put('identity.security_version', $user->security_version);
        $request->session()->forget('identity.mfa_security_version');
        $this->audit->record($request, 'user.mfa_disabled', $user);

        return response()->json(status: 204);
    }

    /** @return list<string> */
    private function generateRecoveryCodes(): array
    {
        return collect(range(1, (int) config('identity.mfa.recovery_code_count', 8)))
            ->map(function (): string {
                $raw = strtoupper(bin2hex(random_bytes(6)));

                return implode('-', str_split($raw, 4));
            })->all();
    }

    private function normalizeRecoveryCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }

    private function forgetPending(Request $request): void
    {
        $request->session()->forget([
            'identity.pending_user_id',
            'identity.pending_expires_at',
            'identity.pending_remember',
        ]);
    }
}
