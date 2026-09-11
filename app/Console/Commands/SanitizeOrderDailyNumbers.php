<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class SanitizeOrderDailyNumbers extends Command
{
    protected $signature = 'orders:sanitize-daily-numbers
        {--execute : Apply the proposed daily_number changes}
        {--trace= : Trace CSV path relative to storage/app}';

    protected $description = 'Preview or repair duplicate daily order numbers without changing order IDs or relations';

    public function handle(): int
    {
        $duplicates = $this->duplicateGroups();
        if ($duplicates->isEmpty()) {
            $this->info('No duplicate daily order numbers were found.');

            return self::SUCCESS;
        }
        if ($duplicates->contains(fn ($group) => $group->branch_id === null)) {
            throw new RuntimeException('Orders without a branch must be resolved before sanitizing daily numbers.');
        }

        $tracePath = $this->option('trace') ?: 'daily-number-sanitization-'.now()->format('Ymd-His').'.csv';
        $rows = [['order_id', 'branch_id', 'order_date', 'old_daily_number', 'new_daily_number']];

        foreach ($duplicates->groupBy(fn ($group) => $group->branch_id.'|'.$group->order_date) as $groups) {
            foreach ($this->planDay((int) $groups->first()->branch_id, $groups->first()->order_date) as $change) {
                $rows[] = $change;
            }
        }

        Storage::disk('local')->put($tracePath, collect($rows)->map(fn (array $row) => implode(',', $row))->implode("\n")."\n");
        $this->table($rows[0], array_slice($rows, 1));
        $this->info('Trace written to storage/app/private/'.$tracePath);

        if (! $this->option('execute')) {
            $this->warn('Preview only: no database rows were modified.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($rows): void {
            $changes = collect(array_slice($rows, 1));
            $branchIds = $changes->pluck(1)->unique()->sort()->values();
            Branch::query()->whereIn('id', $branchIds)->orderBy('id')->lockForUpdate()->get();

            foreach ($changes->groupBy(fn ($row) => $row[1].'|'.$row[2]) as $dayChanges) {
                Order::query()
                    ->where('branch_id', $dayChanges->first()[1])
                    ->whereDate('order_date', $dayChanges->first()[2])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
            }

            $freshRows = collect();
            foreach ($changes->groupBy(fn ($row) => $row[1].'|'.$row[2]) as $dayChanges) {
                $freshRows->push(...$this->planDay((int) $dayChanges->first()[1], $dayChanges->first()[2]));
            }
            if ($freshRows->values()->all() !== $changes->values()->all()) {
                throw new RuntimeException('The duplicate set changed after the preview; rerun the command.');
            }

            foreach ($changes as $change) {
                DB::table('orders')->where('id', $change[0])->update(['daily_number' => $change[4]]);
            }

            if ($this->duplicateGroups()->isNotEmpty()) {
                throw new RuntimeException('Duplicate verification failed; the transaction was rolled back.');
            }
        }, 3);

        $this->info('Daily numbers updated; IDs, relations, and existing documents were left untouched.');

        return self::SUCCESS;
    }

    private function duplicateGroups()
    {
        return Order::query()
            ->select(['branch_id', 'order_date', 'daily_number'])
            ->selectRaw('COUNT(*) AS repetitions')
            ->groupBy('branch_id', 'order_date', 'daily_number')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('branch_id')
            ->orderBy('order_date')
            ->orderBy('daily_number')
            ->get();
    }

    private function planDay(int $branchId, string $date): array
    {
        $date = CarbonImmutable::parse($date)->toDateString();
        $orders = Order::query()
            ->where('branch_id', $branchId)
            ->whereDate('order_date', $date)
            ->withCount('documents')
            ->get();
        $next = (int) $orders->max('daily_number');
        $changes = [];

        foreach ($orders->groupBy('daily_number')->filter(fn ($group) => $group->count() > 1)->sortKeys() as $number => $duplicates) {
            $renumber = $duplicates
                ->sortBy(fn (Order $order) => [
                    -$order->documents_count,
                    $order->status === 'cancelled' ? 1 : 0,
                    $order->created_at?->format('Y-m-d H:i:s.u'),
                    $order->id,
                ])
                ->skip(1);

            foreach ($renumber as $order) {
                $changes[] = [$order->id, $branchId, $date, (int) $number, ++$next];
            }
        }

        return $changes;
    }
}
