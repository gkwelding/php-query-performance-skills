<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;

class ArchiveStaleOrders extends Command
{
    protected $signature = 'orders:archive-stale';

    protected $description = 'Archive every pending order placed more than 30 days ago';

    public function handle(): int
    {
        $archived = 0;

        Order::where('status', 'pending')
            ->where('created_at', '<', now()->subDays(30))
            ->chunk(5, function ($orders) use (&$archived) {
                foreach ($orders as $order) {
                    $order->update(['status' => 'archived']);
                    $this->line("Archived {$order->reference} for {$order->customer->email}");
                    $archived++;
                }
            });

        $this->info("Archived {$archived} orders.");

        return self::SUCCESS;
    }
}
