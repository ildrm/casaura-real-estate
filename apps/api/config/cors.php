<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:3000')],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'Origin', 'Request-ID', 'Agency-ID', 'X-XSRF-TOKEN'],
    'exposed_headers' => ['Request-ID'],
    'max_age' => 600,
    'supports_credentials' => true,
];
