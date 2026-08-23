<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\ApiException;
use App\Domain\Tenancy\AuditRecorder;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Throwable;

class IdentityController extends Controller
{
    private const RECOVERY_MESSAGE = 'If an eligible account exists, recovery instructions will be sent.';

    public function __construct(private readonly AuditRecorder $audit) {}

    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'email:rfc', 'max:254']]);
        $email = mb_strtolower($validated['email']);
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->where('status', 'active')->first();

        if ($user) {
            try {
                Password::broker()->sendResetLink(['email' => $user->email]);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return response()->json(['data' => ['message' => self::RECOVERY_MESSAGE]], 202);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()],
        ]);
        $validated['email'] = mb_strtolower($validated['email']);

        $status = Password::broker()->reset($validated, function (User $user, string $password) use ($request): void {
            DB::transaction(function () use ($request, $user, $password): void {
                $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $beforeVersion = $user->security_version;
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                    'security_version' => $beforeVersion + 1,
                ])->save();
                $user->tokens()->delete();
                DB::table('sessions')->where('user_id', $user->id)->delete();
                $this->audit->record($request, 'user.password_reset', $user, [
                    'security_version' => $beforeVersion,
                ], [
                    'security_version' => $user->security_version,
                ]);
                event(new PasswordReset($user));
            });
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw new ApiException('PASSWORD_RESET_INVALID', 'The password reset link is invalid or has expired.');
        }

        return response()->json(['data' => ['message' => 'Your password has been reset.']]);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user instanceof User && ! $user->hasVerifiedEmail()) {
            try {
                $user->sendEmailVerificationNotification();
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return response()->json(['data' => ['message' => 'If verification is required, a new link will be sent.']], 202);
    }

    public function verifyEmail(Request $request, string $id, string $hash): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User || ! hash_equals((string) $user->getKey(), $id)) {
            throw new ApiException('EMAIL_VERIFICATION_INVALID', 'This verification link is invalid.', 403);
        }
        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            throw new ApiException('EMAIL_VERIFICATION_INVALID', 'This verification link is invalid.', 403);
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
            $this->audit->record($request, 'user.email_verified', $user, [
                'email_verified_at' => null,
            ], [
                'email_verified_at' => $user->email_verified_at?->toISOString(),
            ]);
        }

        return response()->json(['data' => ['verified' => true]]);
    }
}
