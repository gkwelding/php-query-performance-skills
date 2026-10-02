<?php

namespace Tests\Eval;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerIndexEvalTest extends TestCase
{
    use EvalHelpers, RefreshDatabase;

    public function test_response_is_unchanged(): void
    {
        $this->seedShop(5);

        $this->assertSnapshot('customers.json', $this->get('/customers')->assertOk()->getContent());
    }

    public function test_response_reflects_writes(): void
    {
        $this->seedShop(5);
        $this->get('/customers')->assertOk();

        Customer::where('name', 'Zara')->update(['name' => 'Zara Renamed']);
        Customer::where('name', 'Mia')->first()->orders()->create(['reference' => 'ORD-NEW', 'status' => 'pending', 'total_cents' => 1]);

        $this->assertSnapshot('customers-after-write.json', $this->get('/customers')->assertOk()->getContent());
    }

    #[DataProvider('sizes')]
    public function test_query_probe(int $customers): void
    {
        $this->seedShop($customers);

        $this->recordProbe($customers, $this->queriesDuring(fn () => $this->get('/customers')->assertOk()));
    }
}
