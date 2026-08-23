<?php

return [
    'inquiry_consent_version' => env('INQUIRY_CONSENT_VERSION', '2026-08-22'),
    'inquiry_consent_text' => env(
        'INQUIRY_CONSENT_TEXT',
        'I agree that Casaura can share these details with the responsible agency for this property inquiry.',
    ),
    'export_retention_days' => max(1, (int) env('PRIVACY_EXPORT_RETENTION_DAYS', 7)),
    'analytics_pseudonymize_days' => max(1, (int) env('PRIVACY_ANALYTICS_PSEUDONYMIZE_DAYS', 7)),
    'analytics_delete_days' => max(1, (int) env('PRIVACY_ANALYTICS_DELETE_DAYS', 90)),
    'orphan_invitation_days' => max(1, (int) env('PRIVACY_ORPHAN_INVITATION_DAYS', 7)),
];
