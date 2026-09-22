<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ServiceHealth;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __invoke(ServiceHealth $health): JsonResponse
    {
        $report = $health->report();

        return response()->json(['data' => $report], $report['healthy'] ? 200 : 503)
            ->header('Cache-Control', 'private, no-store');
    }
}
