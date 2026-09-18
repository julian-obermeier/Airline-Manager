<?php

return [
    'strategies' => [
        'conservative' => [
            'label' => 'Konservativ',
            'occupancy_weight' => 0.75,
            'time_weight' => 0.70,
            'market_weight' => 0.35,
        ],
        'balanced' => [
            'label' => 'Ausgewogen',
            'occupancy_weight' => 1.00,
            'time_weight' => 1.00,
            'market_weight' => 0.55,
        ],
        'aggressive' => [
            'label' => 'Aggressiv',
            'occupancy_weight' => 1.20,
            'time_weight' => 1.20,
            'market_weight' => 0.75,
        ],
    ],

    'default_policy' => [
        'mode' => 'dynamic',
        'strategy' => 'balanced',
        'floor_percent' => 70,
        'ceiling_percent' => 220,
    ],

    'load_buckets' => [
        ['code' => 'saver', 'label' => 'Saver', 'max_load' => 0.25, 'multiplier' => 0.86],
        ['code' => 'basic', 'label' => 'Basic', 'max_load' => 0.50, 'multiplier' => 1.00],
        ['code' => 'standard', 'label' => 'Standard', 'max_load' => 0.70, 'multiplier' => 1.12],
        ['code' => 'flex', 'label' => 'Flex', 'max_load' => 0.85, 'multiplier' => 1.30],
        ['code' => 'high', 'label' => 'High Demand', 'max_load' => 1.00, 'multiplier' => 1.55],
    ],

    'departure_buckets' => [
        ['code' => 'early', 'label' => 'Frühbucher', 'min_hours' => 504, 'multiplier' => 0.90],
        ['code' => 'advance', 'label' => 'Vorausbuchung', 'min_hours' => 336, 'multiplier' => 0.95],
        ['code' => 'normal', 'label' => 'Normal', 'min_hours' => 168, 'multiplier' => 1.00],
        ['code' => 'short', 'label' => 'Kurzfristig', 'min_hours' => 72, 'multiplier' => 1.10],
        ['code' => 'late', 'label' => 'Last Minute', 'min_hours' => 24, 'multiplier' => 1.24],
        ['code' => 'very_late', 'label' => 'Very Last Minute', 'min_hours' => 0, 'multiplier' => 1.42],
    ],

    'minimum_reprice_delta_percent' => 1.0,
];
