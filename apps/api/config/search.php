<?php

return [
    'driver' => env('SEARCH_DRIVER', 'database'),
    'index' => env('OPENSEARCH_INDEX', 'casaura-listings-v1'),
    'opensearch' => [
        'url' => env('OPENSEARCH_URL', 'http://localhost:9200'),
        'username' => env('OPENSEARCH_USERNAME'),
        'password' => env('OPENSEARCH_PASSWORD'),
        'timeout' => (int) env('OPENSEARCH_TIMEOUT', 3),
    ],
    'max_radius_km' => (float) env('SEARCH_MAX_RADIUS_KM', 200),
];
