<?php

namespace App\Services\NetSuite;

use App\Exceptions\ItemSourceUnavailable;
use Illuminate\Support\Facades\Validator;

class ItemSource
{
    public function __construct(private ItemQueryClient $client) {}

    /** @return list<array<string, mixed>> */
    public function byUpc(string $upc): array
    {
        if (! preg_match('/\A[0-9]{1,255}\z/', $upc)) {
            throw new \InvalidArgumentException('A numeric UPC string is required.');
        }

        return $this->read("item.upccode = '{$upc}'", $upc);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        if ($id < 1) {
            throw new \InvalidArgumentException('A positive NetSuite item ID is required.');
        }
        $items = $this->read('item.id = '.$id);
        if (count($items) > 1 || ($items !== [] && (int) $items[0]['id'] !== $id)) {
            throw new ItemSourceUnavailable('NetSuite returned an unexpected item.');
        }

        return $items[0] ?? null;
    }

    /** @return list<array<string, mixed>> */
    private function read(string $condition, ?string $upc = null): array
    {
        $items = $this->client->all(<<<SQL
            SELECT item.id, item.itemid AS item_number, item.description AS description,
                item.upccode AS upc,
                TO_CHAR(SYS_EXTRACT_UTC(item.lastmodifieddate), 'YYYY-MM-DD HH24:MI:SS') AS updated_at
            FROM item
            WHERE item.isinactive = 'F' AND {$condition}
            ORDER BY item.id
            SQL);
        $seen = [];
        foreach ($items as $item) {
            $validator = Validator::make($item, [
                'id' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
                'item_number' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'upc' => ['nullable', 'string', 'max:255'],
                'updated_at' => ['required', 'date_format:Y-m-d H:i:s'],
            ]);
            if ($validator->fails() || isset($seen[$item['id']]) || ($upc !== null && ($item['upc'] ?? null) !== $upc)) {
                throw new ItemSourceUnavailable('NetSuite returned invalid or duplicate item data.');
            }
            $seen[$item['id']] = true;
        }

        return $items;
    }
}
