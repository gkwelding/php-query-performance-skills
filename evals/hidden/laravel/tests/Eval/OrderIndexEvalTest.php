<?php

namespace Tests\Eval;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderIndexEvalTest extends TestCase
{
    use EvalHelpers, RefreshDatabase;

    public function test_response_is_unchanged(): void
    {
        $this->seedShop(5);

        $this->assertSnapshot('orders.json', $this->get('/orders')->assertOk()->getContent());
    }

    public function test_response_reflects_writes(): void
    {
        $this->seedShop(5);
        $this->get('/orders')->assertOk();

        Customer::where('name', 'Adam')->update(['name' => 'Adam Renamed']);
        Order::where('reference', 'ORD-01-1')->first()->items()->create(['product_name' => 'Late addition', 'quantity' => 1, 'price_cents' => 100]);

        $this->assertSnapshot('orders-after-write.json', $this->get('/orders')->assertOk()->getContent());
    }

    #[DataProvider('sizes')]
    public function test_query_probe(int $customers): void
    {
        $this->seedShop($customers);

        $this->recordProbe($customers, $this->queriesDuring(fn () => $this->get('/orders')->assertOk()));
    }
}
