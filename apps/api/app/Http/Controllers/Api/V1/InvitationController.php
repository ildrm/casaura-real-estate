<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\ApiException;
use App\Domain\Tenancy\AuditRecorder;
use App\Http\Controllers\Controller;
use App\Http\Resources\PrincipalResource;
use App\Models\AgencyMember;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class InvitationController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function accept(Request $request, string $token): JsonResource
    {
        $tokenHash = hash('sha256', $token);

        $user = DB::transaction(function () use ($request, $tokenHash): User {
            $membership = AgencyMember::query()->where('invitation_token_hash', $tokenHash)
                ->with('user')->lockForUpdate()->first();
            if (! $membership || $membership->status !== 'invited' || $membership->invitation_cancelled_at) {
                throw new ApiException('INVITATION_INVALID', 'This invitation is invalid or has already been used.');
            }
            if (! $membership->invitation_expires_at || $membership->invitation_expires_at->isPast()) {
                throw new ApiException('INVITATION_EXPIRED', 'This invitation has expired.', 410);
            }

            $user = User::query()->whereKey($membership->user_id)->lockForUpdate()->firstOrFail();
            if ($user->status !== 'active') {
                throw new ApiException('INVITATION_ACCOUNT_UNAVAILABLE', 'This account cannot accept invitations.', 409);
            }

            if ($membership->invited_user_was_created) {
                $validated = $request->validate([
                    'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()],
                ]);
                $user->forceFill([
                    'password' => Hash::make($validated['password']),
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->save();
                event(new Verified($user));
            } else {
                $authenticated = Auth::guard('sanctum')->user();
                if (! $authenticated instanceof User || ! hash_equals((string) $user->id, (string) $authenticated->id)) {
                    throw new ApiException(
                        'INVITATION_AUTH_REQUIRED',
                        'Sign in with the account that received this invitation.',
                        401,
                    );
                }
            }

            $membership->forceFill([
                'status' => 'active',
                'accepted_at' => now(),
                'invitation_token_hash' => null,
                'invitation_expires_at' => null,
                'invitation_cancelled_at' => null,
            ])->save();
            $this->audit->recordEntity($request, 'agency.invitation_accepted', 'agency_member', $membership->id, [
                'status' => 'invited',
            ], [
                'status' => 'active',
            ], $membership->agency_id);

            return $user;
        });

        if ($request->hasSession()) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
            $request->session()->put('identity.security_version', $user->security_version);
        }

        return new PrincipalResource($user->fresh());
    }
}
