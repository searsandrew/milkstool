<?php

namespace App\Services\NetSuite;

use App\Exceptions\ItemSourceUnavailable;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class BinSource
{
    public function __construct(private ItemQueryClient $client) {}

    /** @return list<array{id: int, number: string, location: array{id: int, name: string}}> */
    public function byNumber(string $number): array
    {
        if (Validator::make(['number' => $number], ['number' => ['required', 'string', 'max:255', 'regex:/\A[^\x00-\x1F\x7F]+\z/u']])->fails()) {
            throw new InvalidArgumentException('A printable bin number is required.');
        }
        $literal = str_replace("'", "''", $number);
        $bins = $this->read("UPPER(bin.binnumber) = UPPER('{$literal}')");
        foreach ($bins as $bin) {
            if (mb_strtoupper($bin['number']) !== mb_strtoupper($number)) {
                throw new ItemSourceUnavailable('NetSuite returned a bin outside the exact lookup.');
            }
        }

        return $bins;
    }

    /** @return array{id: int, number: string, location: array{id: int, name: string}}|null */
    public function find(int $id): ?array
    {
        if ($id < 1) {
            throw new InvalidArgumentException('A positive NetSuite bin ID is required.');
        }
        $bins = $this->read('bin.id = '.$id);
        if (count($bins) > 1 || ($bins !== [] && $bins[0]['id'] !== $id)) {
            throw new ItemSourceUnavailable('NetSuite returned an unexpected bin.');
        }

        return $bins[0] ?? null;
    }

    /** @param array{id: int, number: string, location: array{id: int, name: string}} $bin
     * @return list<array<string, mixed>>
     */
    public function contents(array $bin): array
    {
        $id = $bin['id'];
        $locationId = $bin['location']['id'];
        $balances = $this->client->all(<<<SQL
            SELECT balance.item AS id,
                CASE WHEN COUNT(balance.quantityonhand) = COUNT(*) THEN SUM(balance.quantityonhand) END AS quantity_on_hand,
                CASE WHEN COUNT(balance.quantityavailable) = COUNT(*) THEN SUM(balance.quantityavailable) END AS quantity_available
            FROM InventoryBalance balance
            WHERE balance.binnumber = {$id} AND balance.location = {$locationId}
            GROUP BY balance.item
            HAVING SUM(balance.quantityonhand) <> 0 OR SUM(balance.quantityavailable) <> 0
                OR COUNT(balance.quantityonhand) < COUNT(*) OR COUNT(balance.quantityavailable) < COUNT(*)
            ORDER BY balance.item
            SQL);
        $indexed = [];
        foreach ($balances as $balance) {
            if (Validator::make($balance, [
                'id' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
                'quantity_on_hand' => ['nullable', 'numeric'], 'quantity_available' => ['nullable', 'numeric'],
            ])->fails() || isset($indexed[$balance['id']])) {
                throw new ItemSourceUnavailable('NetSuite returned invalid bin balances.');
            }
            $indexed[(int) $balance['id']] = $balance;
        }
        $items = [];
        foreach (array_chunk(array_keys($indexed), 1000) as $ids) {
            $list = implode(',', $ids);
            $headers = $this->client->all(<<<SQL
                SELECT item.id, item.itemid AS item_number, item.description AS description, item.upccode AS upc
                FROM item WHERE item.id IN ({$list}) ORDER BY item.id
                SQL);
            foreach ($headers as $header) {
                if (Validator::make($header, [
                    'id' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
                    'item_number' => ['required', 'string'], 'description' => ['nullable', 'string'], 'upc' => ['nullable', 'string'],
                ])->fails() || ! isset($indexed[$header['id']]) || isset($items[$header['id']])) {
                    throw new ItemSourceUnavailable('NetSuite returned invalid bin item data.');
                }
                $itemId = (int) $header['id'];
                $balance = $indexed[$itemId];
                $items[$itemId] = [
                    'id' => $itemId, 'item_number' => $header['item_number'], 'description' => $header['description'] ?? null,
                    'upc' => $header['upc'] ?? null,
                    'quantity_on_hand' => $this->quantity($balance['quantity_on_hand'] ?? null),
                    'quantity_available' => $this->quantity($balance['quantity_available'] ?? null),
                ];
            }
        }
        if (count($items) !== count($indexed)) {
            throw new ItemSourceUnavailable('NetSuite did not return all bin items.');
        }
        ksort($items);

        return array_values($items);
    }

    /** @return list<array{id: int, number: string, location: array{id: int, name: string}}> */
    private function read(string $condition): array
    {
        $rows = $this->client->all(<<<SQL
            SELECT bin.id, bin.binnumber AS number, bin.location AS location_id,
                BUILTIN.DF(bin.location) AS location_name
            FROM Bin bin WHERE bin.isinactive = 'F' AND {$condition}
            ORDER BY bin.location, bin.id
            SQL);
        $bins = [];
        $seen = [];
        foreach ($rows as $row) {
            if (Validator::make($row, [
                'id' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX], 'number' => ['required', 'string'],
                'location_id' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX], 'location_name' => ['required', 'string'],
            ])->fails() || isset($seen[$row['id']])) {
                throw new ItemSourceUnavailable('NetSuite returned invalid bin identity data.');
            }
            $seen[$row['id']] = true;
            $bins[] = ['id' => (int) $row['id'], 'number' => $row['number'],
                'location' => ['id' => (int) $row['location_id'], 'name' => $row['location_name']]];
        }

        return $bins;
    }

    private function quantity(int|float|string|null $quantity): ?string
    {
        return $quantity === null ? null : (string) BigDecimal::of((string) $quantity)->toScale(8, RoundingMode::HalfUp);
    }
}
