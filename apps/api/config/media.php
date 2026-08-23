<?php

return [
    'scanner' => env('MEDIA_SCANNER', 'signature'),
    'clamav' => [
        'address' => env('CLAMAV_ADDRESS', 'tcp://clamav:3310'),
        'timeout_seconds' => (int) env('CLAMAV_TIMEOUT_SECONDS', 10),
    ],
    'quarantine_retention_days' => max(1, (int) env('MEDIA_QUARANTINE_RETENTION_DAYS', 30)),
];
