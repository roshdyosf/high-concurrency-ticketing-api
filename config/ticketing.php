<?php

return [
    // Snapshot written to orders.currency at order creation
    'currency' => env('TICKETING_CURRENCY', 'USD'),

    // Seconds a hold (pending order) stays valid
    'hold_ttl' => 600,

    // Max seats per order (also caps GA quantity)
    'max_seats_per_order' => 6,

    // Checkout is refused (409 ORDER_EXPIRING) below this many remaining seconds
    'min_checkout_remaining' => 120,

    // Per currency: minor-unit factor (amount * factor = Stripe amount)
    // and the minimum chargeable amount in major units
    'currencies' => [
        'USD' => ['minor_unit_factor' => 100, 'min_charge' => '0.50'],
    ],

    'admin' => [
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],
];
