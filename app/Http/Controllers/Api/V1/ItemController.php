<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\LookupItems;
use App\Http\Controllers\Controller;
use App\Http\Resources\ItemResource;
use App\Services\NetSuite\ItemInventorySource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ItemController extends Controller
{
    public function index(Request $request, LookupItems $lookup): AnonymousResourceCollection
    {
        $data = $request->validate(['upc' => ['required', 'string', 'max:255', 'regex:/\A[0-9]+\z/']]);
        $items = $lookup->byUpc($data['upc']);

        return ItemResource::collection(collect($items))->additional(['meta' => [
            'match' => 'exact', 'checked_at' => now()->utc()->toIso8601String(),
        ]]);
    }

    public function show(Request $request, string $item, LookupItems $lookup, ItemInventorySource $inventory): ItemResource|JsonResponse
    {
        $data = $request->validate([
            'include' => ['sometimes', 'required', 'in:inventory'],
            'inventory_freshness' => ['sometimes', 'required', 'in:live', 'required_with:include'],
        ]);
        abort_unless(ctype_digit($item) && filter_var($item, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false, 404);
        $includeInventory = ($data['include'] ?? null) === 'inventory';
        abort_if($includeInventory && ! $request->user()?->tokenCan('inventory:read'), 403);
        $record = $lookup->find((int) $item);
        if ($record === null) {
            return response()->json(['error' => ['code' => 'item_not_found', 'message' => 'The item was not found in NetSuite.']], 404);
        }
        $meta = ['item' => [
            'checked_at' => $record->synced_at->utc()->toIso8601String(),
            'source_modified_at' => $record->netsuite_updated_at->utc()->toIso8601String(),
        ]];
        if ($includeInventory) {
            $startedAt = now()->utc()->toIso8601String();
            $record->setRelation('inventory', $inventory->forItem((int) $record->id));
            $meta['inventory'] = [
                'started_at' => $startedAt, 'checked_at' => now()->utc()->toIso8601String(),
                'freshness' => 'live', 'complete' => true, 'quantity_basis' => 'base_unit',
            ];
        }

        return (new ItemResource($record))->additional(['meta' => $meta]);
    }
}
