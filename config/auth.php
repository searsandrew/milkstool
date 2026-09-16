<?php

use App\Models\ApiClient;

return [
    'defaults' => ['guard' => 'sanctum'],
    'guards' => [
        'sanctum' => ['driver' => 'sanctum', 'provider' => 'api_clients'],
    ],
    'providers' => [
        'api_clients' => ['driver' => 'eloquent', 'model' => ApiClient::class],
    ],
];
