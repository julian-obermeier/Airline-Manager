<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Airline Empire crew simulation rules
    |--------------------------------------------------------------------------
    |
    | These values are game rules. They are intentionally configurable and
    | must not be interpreted as a legal flight-time-limitations model.
    |
    */
    'cabin_seats_per_member' => 50,
    'continuous_duty_gap_minutes' => 240,
    'planning_horizon_days' => 90,

    'default_max_duty_minutes_day' => 780,
    'default_min_rest_minutes' => 660,

    'default_salary_minor' => [
        'captain' => 950000,
        'first_officer' => 650000,
        'cabin_crew' => 340000,
    ],
];
