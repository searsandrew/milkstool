<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\NetSuite\ItemOrderSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ItemOrderController extends Controller
{
    public function __invoke(Request $request, string $item, ItemOrderSource $source): JsonResponse
    {
        abort_unless(ctype_digit($item) && filter_var($item, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false, 404);
        $data = $request->validate(['type' => ['required', 'in:sales,purchase']]);
        $result = $source->forItem((int) $item, $data['type']);

        return response()->json(['data' => $result['orders'], 'meta' => [
            'item_id' => (int) $item, 'type' => $data['type'], 'limit' => 20, 'has_more' => $result['has_more'],
            'checked_at' => now()->utc()->toIso8601String(), 'freshness' => 'live',
        ]]);
    }
}
