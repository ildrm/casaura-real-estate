<?php

return [
    'release_id' => env('RELEASE_ID', 'development'),
    'heartbeat_ttl_seconds' => max(60, (int) env('OPERATIONS_HEARTBEAT_TTL_SECONDS', 180)),
    'require_worker_heartbeat' => (bool) env('OPERATIONS_REQUIRE_WORKER_HEARTBEAT', env('APP_ENV') === 'production'),
    'require_scheduler_heartbeat' => (bool) env('OPERATIONS_REQUIRE_SCHEDULER_HEARTBEAT', env('APP_ENV') === 'production'),
    'queue_backlog_degraded' => max(1, (int) env('OPERATIONS_QUEUE_BACKLOG_DEGRADED', 1000)),
    'search_backlog_degraded' => max(1, (int) env('OPERATIONS_SEARCH_BACKLOG_DEGRADED', 100)),
];
