<?php
/**
 * loan-config.php
 * Shared loan configuration — single source of truth for:
 *   - categories, durations, rates, tiers
 *   - per-client loan limits
 *   - principal bounds
 *
 * Include from any page that needs loan rules:
 *     require_once __DIR__ . '/loan-config.php';
 */

if (!isset($loanCategories)) {
    $loanCategories = [
        'monthly' => [
            'label'            => 'Monthly Loan',
            'duration_unit'    => 'months',
            'duration_label'   => 'Months',
            'duration_min'     => 1,
            'duration_max'     => 3,
            'duration_default' => 1,
            'rate_type'        => 'tiered',
            'tiers'            => [
                ['min' => 5000,   'max' => 100000,  'rate' => 20.00],
                ['min' => 100001, 'max' => 500000,  'rate' => 18.00],
                ['min' => 500001, 'max' => 1000000, 'rate' => 15.00],
            ],
            'default_mult'     => 3,
        ],
        'daily' => [
            'label'            => 'Daily Loan',
            'duration_unit'    => 'days',
            'duration_label'   => 'Days',
            'duration_min'     => 1,
            'duration_max'     => 7,
            'duration_default' => 1,
            'rate_type'        => 'flat',
            'rate'             => 2.50,
            'default_mult'     => 3,
        ],
        'boost' => [
            'label'            => 'Jolly Boost Loan',
            'duration_unit'    => 'days',
            'duration_label'   => 'Days',
            'duration_min'     => 5,
            'duration_max'     => 5,
            'duration_default' => 5,
            'rate_type'        => 'flat',
            'rate'             => 0.00,
            'default_mult'     => 3,
        ],
    ];
}

if (!isset($loanLimits)) {
    $loanLimits = [
        'monthly' => 3,
        'daily'   => 2,
        'boost'   => 0,
    ];
}

if (!isset($minPrincipal)) $minPrincipal = 5000.00;
if (!isset($maxPrincipal)) $maxPrincipal = 1000000.00;