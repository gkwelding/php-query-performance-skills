<?php

namespace Tests\Eval;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;

// Copied into the scratch app by evals/run.sh only after Claude has finished, so neither variant sees it.
trait EvalHelpers
{
    private const NAMES = ['Zara', 'Adam', 'Mia', 'Bo', 'Ivy', 'Eli', 'Noor', 'Cal', 'Uma', 'Gus'];

    public static function sizes(): array
    {
        return ['small' => [3], 'large' => [10]];
    }

    // Names out of alphabetical order, every third customer without orders, 0-2 items per order.
    private function seedShop(int $customers): void
    {
        foreach (array_slice(self::NAMES, 0, $customers) as $c => $name) {
            $customer = Customer::create(['name' => $name, 'email' => strtolower($name).'@example.com']);
            if ($c % 3 === 2) {
                continue;
            }
            foreach ([1, 2] as $o) {
                $order = $customer->orders()->create([
                    'reference' => sprintf('ORD-%02d-%d', $c + 1, $o),
                    'status' => $o === 1 ? 'shipped' : 'pending',
                    'total_cents' => 1000 * ($c + 1) + 99 * $o,
                ]);
                for ($i = 1; $i <= ($c + $o) % 3; $i++) {
                    $order->items()->create(['product_name' => "Product {$i}", 'quantity' => $i, 'price_cents' => 250 * $i]);
                }
            }
        }
    }

    // Pending orders older than 30 days, plus recent and shipped ones the command must leave alone.
    private function seedStaleOrders(int $stale): void
    {
        $customers = array_map(
            fn ($name) => Customer::create(['name' => $name, 'email' => strtolower($name).'@example.com']),
            array_slice(self::NAMES, 0, 3),
        );
        foreach ([['pending', 5], ['shipped', 40], ['pending', 10], ['shipped', 90]] as $i => [$status, $days]) {
            $customers[$i % 3]->orders()->create(['reference' => "ORD-K{$i}", 'status' => $status, 'total_cents' => 500, 'created_at' => now()->subDays($days)]);
        }
        for ($i = 1; $i <= $stale; $i++) {
            $customers[$i % 3]->orders()->create(['reference' => "ORD-S{$i}", 'status' => 'pending', 'total_cents' => 100 * $i, 'created_at' => now()->subDays(30 + $i)]);
        }
    }

    // Counts with DB::listen rather than preventLazyLoading(), which ignores one-row results and relation method calls.
    private function queriesDuring(callable $act): int
    {
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });
        $act();

        return $count;
    }

    // Read by evals/score.php: one "<size> <queries>" line per probe run, in the app's working directory.
    private function recordProbe(int $size, int $queries): void
    {
        $this->assertGreaterThan(0, $queries);
        file_put_contents('eval-probe.txt', "{$size} {$queries}\n", FILE_APPEND);
    }

    // Exact bytes. EVAL_WRITE_SNAPSHOTS=1 rewrites the snapshot (only for maintaining the eval).
    private function assertSnapshot(string $name, string $actual): void
    {
        $path = __DIR__."/snapshots/{$name}";
        if (getenv('EVAL_WRITE_SNAPSHOTS')) {
            file_put_contents($path, $actual);
        }
        $this->assertSame(file_get_contents($path), $actual, "Output differs from snapshot {$name}");
    }
}
