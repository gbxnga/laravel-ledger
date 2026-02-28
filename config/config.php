<?php


return [
    'api' => true,
    'api_debug' => env('LEDGER_API_DEBUG_ALLOWED', true),
    // 'chartPath' => 'your/custom/path',
    'log' => env('LEDGER_LOG_CHANNEL', env('LOG_CHANNEL')),
    'middleware' => ['api'],
    'prefix' => 'api/ledger',
    // To pass API-specific options to a report, use the reportApiOptions
    // setting. Example:
    // 'reportApiOptions' => [
    //     'trialBalance' => [
    //         'maxDepth' => 3,
    //     ],
    // ],
    //
    // Extended reports with the reports setting. Reports is an array indexed by
    // report name that references the reporting class (which should extend
    // `Abivia\Ledger\Reports\AbstractReport`). If there is a reports setting,
    // it must list all reports available to the JSON API. Any reports omitted
    // from this list (e.g. trialBalance) will be inaccessible to the JSON API.
    // 'reports' => [
    //     'trialBalance' => Abivia\Ledger\Reports\TrialBalanceReport::class,
    // ],
    'performance' => [
        'batch' => [
            // Collapse consecutive entry/add operations into a bulk write pass.
            'coalesce_entry_add' => env('LEDGER_BATCH_COALESCE_ENTRY_ADD', true),
            // Collapse consecutive entry/delete operations into a bulk delete pass.
            'coalesce_entry_delete' => env('LEDGER_BATCH_COALESCE_ENTRY_DELETE', true),
            // Minimum consecutive entry add/delete operations required before coalescing.
            'coalesce_min_group' => env('LEDGER_BATCH_COALESCE_MIN_GROUP', 2),
        ],
        'entry' => [
            // Max rows per journal_details insert.
            'detail_chunk' => env('LEDGER_ENTRY_DETAIL_CHUNK', 1000),
            // Max rows/keys per ledger_balances seed, lock, and upsert pass.
            'balance_chunk' => env('LEDGER_ENTRY_BALANCE_CHUNK', 500),
        ],
        'metrics' => [
            // Enable structured performance logs for entry posting paths.
            'enabled' => env('LEDGER_PERFORMANCE_METRICS', false),
        ],
        'root' => [
            // Max rows per opening-balance journal_details insert.
            'detail_chunk' => env('LEDGER_ROOT_DETAIL_CHUNK', 1000),
            // Max rows per opening-balance ledger_balances upsert.
            'balance_chunk' => env('LEDGER_ROOT_BALANCE_CHUNK', 500),
        ],
    ],
    'session_key_prefix' => env('LEDGER_SESSION_PREFIX', 'ledger.'),
];
