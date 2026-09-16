<?php

return [
    /*
    |--------------------------------------------------------------------------
    | NetSuite Account / Realm
    |--------------------------------------------------------------------------
    */
    'account' => env('NETSUITE_ACCOUNT'),

    /*
    |--------------------------------------------------------------------------
    | Token-Based Auth (OAuth 1.0a)
    |--------------------------------------------------------------------------
    */
    'consumer_key' => env('NETSUITE_CONSUMER_KEY'),
    'consumer_secret' => env('NETSUITE_CONSUMER_SECRET'),

    // Prefer NETSUITE_TOKEN_ID, but allow NETSUITE_TOKEN
    'token_id' => env('NETSUITE_TOKEN_ID', env('NETSUITE_TOKEN')),
    'token_secret' => env('NETSUITE_TOKEN_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | RESTlet
    |--------------------------------------------------------------------------
    */
    'restlet_base_url' => env('NETSUITE_RESTLET_BASE_URL'),
    'restlet_script_id' => env('NETSUITE_RESTLET_SCRIPT_ID'),
    'restlet_deploy_id' => env('NETSUITE_RESTLET_DEPLOY_ID', 1),

    /*
    |--------------------------------------------------------------------------
    | SuiteTalk REST (REST Record + SuiteQL)
    |--------------------------------------------------------------------------
    | Optional override. If not set, briar-rose builds:
    | https://{account}.suitetalk.api.netsuite.com
    |
    | Back-compat: NETSUITE_BASE_URL
    */
    'rest_base_url' => env('NETSUITE_REST_BASE_URL', env('NETSUITE_BASE_URL')),
    'rest' => [
        'default_limit' => env('NETSUITE_REST_DEFAULT_LIMIT', 1000),
        'retries' => [
            'enabled' => env('NETSUITE_REST_RETRY', true),
            'max_attempts' => env('NETSUITE_REST_RETRY_MAX', 5),
            'base_delay_ms' => env('NETSUITE_REST_RETRY_BASE_DELAY_MS', 250),
            'max_delay_ms' => env('NETSUITE_REST_RETRY_MAX_DELAY_MS', 5000),
            'statuses' => [429, 500, 502, 503, 504],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Environment (for future flexibility)
    |--------------------------------------------------------------------------
    */
    'environment' => env('NETSUITE_ENV', 'production'), // production|sandbox

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    */
    'timeout' => env('NETSUITE_TIMEOUT', 30),
    'connect_timeout' => env('NETSUITE_CONNECT_TIMEOUT', 10),
    'retry' => env('NETSUITE_RETRY', 3),
    'retry_sleep_ms' => env('NETSUITE_RETRY_SLEEP_MS', 250),

    /*
    |--------------------------------------------------------------------------
    | Debug logging (redacted)
    |--------------------------------------------------------------------------
    */
    'log_requests' => env('NETSUITE_LOG_REQUESTS', false),
];
