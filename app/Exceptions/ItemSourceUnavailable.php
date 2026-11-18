<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ItemSourceUnavailable extends RuntimeException
{
    public function render(Request $request): JsonResponse
    {
        return response()->json(['error' => [
            'code' => 'source_unavailable',
            'message' => 'Current item data could not be retrieved from NetSuite. Please retry.',
        ]], 503, ['Cache-Control' => 'private, no-store', 'Retry-After' => '5']);
    }
}
