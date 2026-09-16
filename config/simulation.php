<?php

return [
    'cron_token' => env('SIMULATION_CRON_TOKEN'),
    'boarding_minutes' => (int) env('SIMULATION_BOARDING_MINUTES', 30),
    'booking_window_days' => (int) env('SIMULATION_BOOKING_WINDOW_DAYS', 14),
    'delay_evaluation_minutes' => (int) env('SIMULATION_DELAY_EVALUATION_MINUTES', 180),
    'fuel_price_minor_per_liter' => (int) env('SIMULATION_FUEL_PRICE_MINOR_PER_LITER', 88),
];
