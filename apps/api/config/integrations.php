<?php

return [
    'page_size' => (int) env('RESO_PAGE_SIZE', 200),
    'timeout_seconds' => (int) env('RESO_TIMEOUT_SECONDS', 15),
    'raw_payload_retention_days' => (int) env('RESO_RAW_PAYLOAD_RETENTION_DAYS', 30),
    'approved_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('RESO_APPROVED_ORIGINS', ''))))),
    'secret_directory' => env('RESO_SECRET_DIRECTORY'),
    // Direct values are reserved for deterministic tests and local development.
    // Production resolves named references from the mounted secret directory.
    'secrets' => [],
];
