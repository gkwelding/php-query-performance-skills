<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Order */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'items' => $this->items->map(fn ($item) => [
                'product' => $item->product_name,
                'quantity' => $item->quantity,
                'line_total' => number_format($item->quantity * $item->price_cents / 100, 2),
            ])->all(),
        ];
    }
}
