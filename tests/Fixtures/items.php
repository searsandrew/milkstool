<?php

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function sourceItem(array $overrides = []): array
{
    return array_replace([
        'id' => '1234', 'item_number' => 'ABC-100', 'description' => 'Example product',
        'upc' => '001234567890', 'updated_at' => '2026-11-18 12:00:00',
    ], $overrides);
}

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function sourceItemLocation(array $overrides = []): array
{
    return array_replace(['id' => '10', 'name' => 'Main Warehouse',
        'quantity_on_hand' => '24', 'quantity_available' => '20'], $overrides);
}

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function sourceItemBin(array $overrides = []): array
{
    return array_replace(['id' => '101', 'number' => 'A-01-02', 'location_id' => '10',
        'quantity_on_hand' => '12', 'quantity_available' => '10'], $overrides);
}
