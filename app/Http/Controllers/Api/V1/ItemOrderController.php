<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\NetSuite\ItemOrderSource;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ItemOrderController extends Controller
{
    public function __invoke(Request $request, string $item, ItemOrderSource $source): JsonResponse
    {
        abort_unless(ctype_digit($item) && filter_var($item, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false, 404);
        $data = $request->validate(['type' => ['required', 'in:sales,purchase'], 'cursor' => ['sometimes', 'required', 'string', 'max:4096']]);
        $before = null;
        if (isset($data['cursor'])) {
            try {
                $cursor = json_decode(Crypt::decryptString($data['cursor']), true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($cursor) || Validator::make($cursor, [
                    'item_id' => ['required', 'integer', 'in:'.$item], 'type' => ['required', 'in:'.$data['type']],
                    'date' => ['required', 'date_format:Y-m-d'], 'id' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
                ])->fails()) {
                    throw ValidationException::withMessages(['cursor' => 'The pagination cursor is invalid.']);
                }
                $before = ['date' => $cursor['date'], 'id' => (int) $cursor['id']];
            } catch (DecryptException|\JsonException) {
                throw ValidationException::withMessages(['cursor' => 'The pagination cursor is invalid.']);
            }
        }
        $result = $source->forItem((int) $item, $data['type'], $before);
        $nextCursor = null;
        if ($result['has_more']) {
            $last = $result['orders'][array_key_last($result['orders'])];
            $nextCursor = Crypt::encryptString(json_encode([
                'item_id' => (int) $item, 'type' => $data['type'], 'date' => $last['order_date'], 'id' => $last['id'],
            ], JSON_THROW_ON_ERROR));
        }

        return response()->json(['data' => $result['orders'], 'meta' => [
            'item_id' => (int) $item, 'type' => $data['type'], 'limit' => 10, 'has_more' => $result['has_more'], 'next_cursor' => $nextCursor,
            'checked_at' => now()->utc()->toIso8601String(), 'freshness' => 'live',
        ]]);
    }
}
