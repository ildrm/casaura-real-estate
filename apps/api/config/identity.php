<?php

return [
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

    'legal' => [
        'version' => env('LEGAL_DOCUMENT_VERSION', '2026-08-22'),
        'text' => env(
            'LEGAL_DOCUMENT_TEXT',
            'By creating an account, the user accepts the Casaura Terms of Service and acknowledges the Privacy Notice.',
        ),
    ],

    'mfa' => [
        'issuer' => env('MFA_ISSUER', env('APP_NAME', 'Casaura')),
        'challenge_ttl_seconds' => 300,
        'recovery_code_count' => 8,
    ],

    'invitations' => [
        'ttl_hours' => max(1, (int) env('INVITATION_TTL_HOURS', 72)),
    ],
];
