<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\URL;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment(['local', 'testing'])) {
    fwrite(STDERR, "The E2E verification-link helper is available only in local and testing environments.\n");
    exit(2);
}

$email = mb_strtolower(trim((string) ($argv[1] ?? '')));
$user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

if (! $user) {
    fwrite(STDERR, "No E2E user exists for the requested email.\n");
    exit(3);
}

$verificationUrl = URL::temporarySignedRoute('verification.verify', now()->addMinutes(5), [
    'id' => $user->getKey(),
    'hash' => sha1($user->getEmailForVerification()),
]);

echo rtrim((string) config('identity.frontend_url'), '/')
    .'/verify-email/confirm?'.http_build_query(['verification_url' => $verificationUrl]);
