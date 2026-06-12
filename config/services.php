<?php

return [
    'stripe' => [
        'public_key' => env('STRIPE_PUBLIC_KEY'),
        'secret' => env('STRIPE_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'currency' => env('STRIPE_CURRENCY', 'usd'),
        'payment_amount' => (float) env('STRIPE_PAYMENT_AMOUNT', 100),
        'payment_amount_cents' => (int) env('STRIPE_PAYMENT_AMOUNT_CENTS', 10000),
        'payment_timeout_minutes' => (int) env('STRIPE_PAYMENT_TIMEOUT_MINUTES', 10),
        'product_name' => env('STRIPE_PRODUCT_NAME', 'Pago Postulacion Universitaria'),
    ],
];
