<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Baseline Days
    |--------------------------------------------------------------------------
    |
    | Number of historical days used to build the baseline distribution
    | for normalizing signals. A longer baseline gives more stable results.
    |
    */
    'baseline_days' => 56,

    /*
    |--------------------------------------------------------------------------
    | Follow-Up Horizon Days
    |--------------------------------------------------------------------------
    |
    | Number of days after each analysis window to scan for follow-up
    | fix commits that touch the same files.
    |
    */
    'follow_up_horizon_days' => 3,

    /*
    |--------------------------------------------------------------------------
    | Keywords
    |--------------------------------------------------------------------------
    |
    | Regex patterns used to classify commit messages.
    |
    */
    'keywords' => [
        'fix_pattern' => '/\b(fix|bug|hotfix|patch|typo|oops)\b/i',
        'revert_pattern' => '/\b(revert)\b/i',
        'wip_pattern' => '/\b(wip|tmp|debug|hack)\b/i',
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Configuration
    |--------------------------------------------------------------------------
    |
    | Optional AI-powered explanation layer. Disabled by default.
    | Engines: openai, anthropic, deepseek, google
    |
    | base_url: Custom API endpoint for self-hosted models or proxies
    | timeout: Request timeout in seconds
    |
    */
    'ai' => [
        'enabled' => false,
        'engine' => env('CODEMETRY_AI_ENGINE', 'openai'),
        'api_key' => env('CODEMETRY_AI_API_KEY'),
        'model' => env('CODEMETRY_AI_MODEL'),
        'base_url' => env('CODEMETRY_AI_BASE_URL'),
        'timeout' => env('CODEMETRY_AI_TIMEOUT', 30),
    ],

];
