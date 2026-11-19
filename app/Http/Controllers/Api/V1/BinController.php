<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BinResource;
use App\Services\NetSuite\BinSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BinController extends Controller
{
    public function index(Request $request, BinSource $source): AnonymousResourceCollection
    {
        $data = $request->validate(['number' => ['required', 'string', 'max:255', 'regex:/\A[^\x00-\x1F\x7F]+\z/u']]);

        return BinResource::collection(collect($source->byNumber($data['number'])))
            ->additional(['meta' => ['match' => 'exact', 'checked_at' => now()->utc()->toIso8601String()]]);
    }

    public function show(string $bin, BinSource $source): BinResource|JsonResponse
    {
        abort_unless(ctype_digit($bin) && filter_var($bin, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false, 404);
        $record = $source->find((int) $bin);
        if ($record === null) {
            return response()->json(['error' => ['code' => 'bin_not_found', 'message' => 'The bin was not found in NetSuite.']], 404);
        }
        $record['items'] = $source->contents($record);

        return (new BinResource($record))->additional(['meta' => ['inventory' => [
            'checked_at' => now()->utc()->toIso8601String(), 'freshness' => 'live',
            'complete' => true, 'quantity_basis' => 'base_unit',
        ]]]);
    }
}
