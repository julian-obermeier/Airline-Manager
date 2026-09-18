<?php

return [
    'initial_scores' => [
        'awareness' => 12.0,
        'reputation' => 50.0,
        'satisfaction' => 55.0,
        'service_quality' => 55.0,
        'route_awareness' => 10.0,
    ],

    'campaign_min_budget_minor' => 1000000,
    'campaign_max_budget_minor' => 500000000,

    'channels' => [
        'digital' => ['label' => 'Digital & Social', 'reach' => 1.15, 'route_focus' => 1.10],
        'search' => ['label' => 'Search & Performance', 'reach' => 1.00, 'route_focus' => 1.25],
        'outdoor' => ['label' => 'Out-of-Home', 'reach' => 1.10, 'route_focus' => 0.90],
        'business' => ['label' => 'Corporate Sales', 'reach' => 0.85, 'route_focus' => 1.30],
        'brand' => ['label' => 'Brand Campaign', 'reach' => 1.30, 'route_focus' => 0.80],
    ],

    'max_active_campaign_boost' => 0.40,
    'max_demand_multiplier' => 1.65,
    'min_demand_multiplier' => 0.55,
];
