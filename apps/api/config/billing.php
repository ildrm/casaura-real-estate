<?php

return [
    'driver' => env('BILLING_DRIVER', 'deterministic'),
    'currency' => 'USD',
    'stripe' => [
        'secret_key' => env('STRIPE_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'api_url' => rtrim((string) env('STRIPE_API_URL', 'https://api.stripe.com'), '/'),
        'api_version' => env('STRIPE_API_VERSION', '2025-07-30.basil'),
        'professional_price_id' => env('STRIPE_PROFESSIONAL_PRICE_ID'),
        'webhook_tolerance_seconds' => (int) env('STRIPE_WEBHOOK_TOLERANCE_SECONDS', 300),
    ],
    'checkout_success_url' => env('BILLING_CHECKOUT_SUCCESS_URL', env('FRONTEND_URL', 'http://localhost:3000').'/agency/billing?checkout=success'),
    'checkout_cancel_url' => env('BILLING_CHECKOUT_CANCEL_URL', env('FRONTEND_URL', 'http://localhost:3000').'/agency/billing?checkout=cancelled'),
    'portal_return_url' => env('BILLING_PORTAL_RETURN_URL', env('FRONTEND_URL', 'http://localhost:3000').'/agency/billing'),
];
