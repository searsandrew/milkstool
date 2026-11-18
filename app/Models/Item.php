<?php

namespace App\Models;

use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'item_number', 'description', 'upc', 'netsuite_updated_at', 'synced_at'])]
class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory;

    public $incrementing = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['id' => 'integer', 'netsuite_updated_at' => 'immutable_datetime', 'synced_at' => 'immutable_datetime'];
    }
}
