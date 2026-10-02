<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class OrderController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = Cache::remember('orders.index', 3600, function () {
            $rows = [];
            foreach (Order::orderByDesc('id')->get() as $order) {
                $rows[] = [
                    'id' => $order->id,
                    'reference' => $order->reference,
                    'status' => $order->status,
                    'customer' => $order->customer->name,
                    'item_count' => $order->items()->count(),
                    'total' => number_format($order->total_cents / 100, 2),
                ];
            }

            return $rows;
        });

        return response()->json(['data' => $rows]);
    }
}
