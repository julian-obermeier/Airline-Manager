<?php

return [
    'window_days' => 7,

    'weights' => [
        'price' => 0.25,
        'frequency' => 0.20,
        'reputation' => 0.15,
        'service' => 0.10,
        'awareness' => 0.10,
        'schedule' => 0.10,
        'marketing' => 0.10,
    ],

    'competition_multiplier' => [
        'floor' => 0.35,
        'share_weight' => 0.65,
    ],

    'factor_limits' => [
        'minimum' => 0.40,
        'maximum' => 1.60,
    ],
];
