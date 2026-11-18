<?php

namespace App\Actions;

use App\Models\Item;
use App\Services\NetSuite\ItemSource;
use Illuminate\Support\Facades\DB;

class LookupItems
{
    public function __construct(private ItemSource $source) {}

    /** @return list<Item> */
    public function byUpc(string $upc): array
    {
        $items = $this->source->byUpc($upc);

        return DB::transaction(fn (): array => array_map(fn (array $item): Item => $this->store($item), $items));
    }

    public function find(int $id): ?Item
    {
        $item = $this->source->find($id);

        return $item === null ? null : $this->store($item);
    }

    /** @param array<string, mixed> $item */
    private function store(array $item): Item
    {
        Item::query()->upsert([[
            'id' => (int) $item['id'],
            'item_number' => $item['item_number'],
            'description' => $item['description'] ?? null,
            'upc' => $item['upc'] ?? null,
            'netsuite_updated_at' => $item['updated_at'],
            'synced_at' => now(),
        ]], ['id'], ['item_number', 'description', 'upc', 'netsuite_updated_at', 'synced_at', 'updated_at']);

        return Item::query()->findOrFail((int) $item['id']);
    }
}
