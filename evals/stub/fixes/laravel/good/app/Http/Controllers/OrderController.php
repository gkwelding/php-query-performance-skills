<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

class OrderController extends Controller
{
    public function index(): JsonResponse
    {
        $orders = Order::with('customer')->withCount('items')->orderByDesc('id')->get();

        $rows = [];
        foreach ($orders as $order) {
            $rows[] = [
                'id' => $order->id,
                'reference' => $order->reference,
                'status' => $order->status,
                'customer' => $order->customer->name,
                'item_count' => $order->items_count,
                'total' => number_format($order->total_cents / 100, 2),
            ];
        }

        return response()->json(['data' => $rows]);
    }
}
