<?php

namespace App\Services\NetSuite;

use App\Exceptions\ItemSourceUnavailable;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Validator;

class ItemInventorySource
{
    public function __construct(private ItemQueryClient $client) {}

    /** @return array{unit: array{id: int, name: string}|null, locations: list<array<string, mixed>>} */
    public function forItem(int $itemId): array
    {
        if ($itemId < 1) {
            throw new \InvalidArgumentException('A positive NetSuite item ID is required.');
        }
        $unit = $this->baseUnit($itemId);
        $locations = $this->client->all(<<<SQL
            SELECT balance.location AS id, BUILTIN.DF(balance.location) AS name,
                balance.quantityonhand AS quantity_on_hand, balance.quantityavailable AS quantity_available
            FROM AggregateItemLocation balance
            WHERE balance.item = {$itemId}
            ORDER BY balance.location
            SQL);
        $indexed = [];
        foreach ($locations as $location) {
            $this->validateBalance($location, ['id' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX], 'name' => ['required', 'string']]);
            $id = (int) $location['id'];
            if (isset($indexed[$id])) {
                throw new ItemSourceUnavailable('NetSuite returned duplicate inventory locations.');
            }
            $indexed[$id] = [
                'id' => $id, 'name' => $location['name'],
                'quantity_on_hand' => $this->quantity($location['quantity_on_hand'] ?? null),
                'quantity_available' => $this->quantity($location['quantity_available'] ?? null),
                'bins' => [],
            ];
        }
        $bins = $this->client->all(<<<SQL
            SELECT balance.location AS location_id, balance.binnumber AS id,
                bin.binnumber AS number,
                SUM(balance.quantityonhand) AS quantity_on_hand,
                SUM(balance.quantityavailable) AS quantity_available
            FROM InventoryBalance balance
            JOIN Bin bin ON bin.id = balance.binnumber
            WHERE balance.item = {$itemId} AND balance.binnumber IS NOT NULL
            GROUP BY balance.location, balance.binnumber, bin.binnumber
            ORDER BY balance.location, balance.binnumber
            SQL);
        $seen = [];
        foreach ($bins as $bin) {
            $this->validateBalance($bin, [
                'location_id' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
                'id' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX], 'number' => ['required', 'string'],
            ]);
            $locationId = (int) $bin['location_id'];
            $id = (int) $bin['id'];
            if (! isset($indexed[$locationId]) || isset($seen[$locationId.':'.$id])) {
                throw new ItemSourceUnavailable('NetSuite returned inconsistent inventory bins.');
            }
            $seen[$locationId.':'.$id] = true;
            $indexed[$locationId]['bins'][] = [
                'id' => $id, 'number' => $bin['number'],
                'quantity_on_hand' => $this->quantity($bin['quantity_on_hand'] ?? null),
                'quantity_available' => $this->quantity($bin['quantity_available'] ?? null),
            ];
        }

        return [
            'unit' => $unit,
            'locations' => array_values($indexed),
        ];
    }

    /** @return array{id: int, name: string}|null */
    private function baseUnit(int $itemId): ?array
    {
        $items = $this->client->all("SELECT item.unitstype AS unit_type_id FROM item WHERE item.id = {$itemId} ORDER BY item.id");
        if (count($items) > 1 || ($items !== [] && Validator::make($items[0], [
            'unit_type_id' => ['nullable', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
        ])->fails())) {
            throw new ItemSourceUnavailable('NetSuite returned an invalid inventory units type.');
        }
        if (! isset($items[0]['unit_type_id'])) {
            return null;
        }
        $record = $this->client->unitsType((int) $items[0]['unit_type_id']);
        if ($record === null) {
            return null;
        }
        $base = null;
        foreach ($record['uom']['items'] as $unit) {
            if (! is_array($unit) || Validator::make($unit, ['baseUnit' => ['required', 'boolean']])->fails()) {
                throw new ItemSourceUnavailable('NetSuite returned an invalid inventory unit.');
            }
            if (! $unit['baseUnit']) {
                continue;
            }
            if ($base !== null || Validator::make($unit, [
                'internalId' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
                'unitName' => ['required', 'string'], 'conversionRate' => ['required', 'numeric'],
            ])->fails()) {
                throw new ItemSourceUnavailable('NetSuite returned an invalid inventory base unit.');
            }
            if (! BigDecimal::of((string) $unit['conversionRate'])->isEqualTo(1)) {
                throw new ItemSourceUnavailable('NetSuite returned an invalid base unit conversion.');
            }
            $base = ['id' => (int) $unit['internalId'], 'name' => $unit['unitName']];
        }
        if ($base === null) {
            throw new ItemSourceUnavailable('NetSuite did not return an inventory base unit.');
        }

        return $base;
    }

    /** @param array<string, mixed> $balance
     * @param  array<string, array<int, string>>  $rules
     */
    private function validateBalance(array $balance, array $rules): void
    {
        if (Validator::make($balance, [...$rules,
            'quantity_on_hand' => ['nullable', 'numeric'], 'quantity_available' => ['nullable', 'numeric'],
        ])->fails()) {
            throw new ItemSourceUnavailable('NetSuite returned invalid inventory quantities.');
        }
    }

    private function quantity(int|float|string|null $quantity): ?string
    {
        return $quantity === null ? null : (string) BigDecimal::of((string) $quantity)->toScale(8, RoundingMode::HalfUp);
    }
}
