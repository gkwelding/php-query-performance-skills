<?php

namespace Tests\Eval;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

// The command's contract is "archive every pending order placed more than 30 days ago". The fixture's
// chunk() skips rows because the callback changes a column the query filters on, so the baseline fails this.
class ArchiveStaleOrdersEvalTest extends TestCase
{
    use EvalHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-06-30 12:00:00');
    }

    public function test_archives_every_stale_pending_order(): void
    {
        $this->seedStaleOrders(12);

        Artisan::call('orders:archive-stale');

        $this->assertSnapshot('archive-stale.txt', Artisan::output());
        $this->assertSame(
            ['archived' => 12, 'pending' => 2, 'shipped' => 2],
            Order::query()->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->orderBy('status')
                ->pluck('n', 'status')->map(fn ($n) => (int) $n)->all(),
        );
    }

    public static function staleSizes(): array
    {
        return ['small' => [6], 'large' => [20]];
    }

    #[DataProvider('staleSizes')]
    public function test_query_probe(int $stale): void
    {
        $this->seedStaleOrders($stale);

        $this->recordProbe($stale, $this->queriesDuring(fn () => Artisan::call('orders:archive-stale')));
    }
}
