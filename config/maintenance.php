<?php

return [
    'condition_grounding_percent' => (float) env('MAINTENANCE_GROUNDING_CONDITION', 70),
    'condition_warning_percent' => (float) env('MAINTENANCE_WARNING_CONDITION', 85),

    'checks' => [
        'a_check' => [
            'label' => 'A-Check',
            'interval_hours' => 600,
            'interval_cycles' => 400,
            'grace_hours' => 75,
            'grace_cycles' => 50,
            'duration_hours' => 8,
            'base_cost_minor' => 7500000,
            'seat_cost_minor' => 20000,
            'minimum_condition_after' => 97.0,
        ],
        'c_check' => [
            'label' => 'C-Check',
            'interval_hours' => 6000,
            'interval_cycles' => 3500,
            'grace_hours' => 300,
            'grace_cycles' => 175,
            'duration_hours' => 72,
            'base_cost_minor' => 75000000,
            'seat_cost_minor' => 150000,
            'minimum_condition_after' => 100.0,
        ],
        'repair' => [
            'label' => 'Technische Instandsetzung',
            'duration_hours' => 12,
            'base_cost_minor' => 15000000,
            'seat_cost_minor' => 25000,
            'condition_point_cost_minor' => 500000,
            'condition_restore_percent' => 15.0,
        ],
    ],
];
