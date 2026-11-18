<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'item_number' => $this->item_number,
            'description' => $this->description,
            'upc' => $this->upc,
            'inventory' => $this->when($this->resource->relationLoaded('inventory'), fn () => $this->resource->getRelation('inventory')),
        ];
    }
}
