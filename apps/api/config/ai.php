<?php

return [
    'driver' => env('AI_DRIVER', 'deterministic'),
    'model' => env('OPENAI_MODEL', 'gpt-5-mini'),
    'api_key' => env('OPENAI_API_KEY'),
    'base_url' => rtrim((string) env('OPENAI_BASE_URL', 'https://api.openai.com'), '/'),
    'timeout_seconds' => (int) env('AI_TIMEOUT_SECONDS', 15),
    'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 800),
    'retention_days' => (int) env('AI_CONTENT_RETENTION_DAYS', 30),
];
