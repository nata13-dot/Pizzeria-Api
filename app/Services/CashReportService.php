<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\CashDay;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Purchase;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

class CashReportService
{
    public function summary(int $branchId, mixed $date): array
    {
        $timezone = Branch::query()->whereKey($branchId)->value('timezone') ?: config('app.timezone');
        $dateValue = $date instanceof DateTimeInterface ? $date->format('Y-m-d') : (string) $date;
        $localDate = CarbonImmutable::parse($dateValue, $timezone)->toDateString();
        $startsAt = CarbonImmutable::parse($localDate, $timezone)->startOfDay()->utc();
        $endsAt = $startsAt->addDay();
        $orders = Order::query()
            ->where('branch_id', $branchId)
            ->whereDate('order_date', $localDate)
            ->get();
        $accountedOrders = $orders->whereNotIn('status', ['draft', 'pending_payment', 'cancelled']);
        $paidOrders = $accountedOrders->where('courtesy', false);
        $courtesyOrders = $accountedOrders->where('courtesy', true);

        // Cash balance follows the date the money was actually received. This is
        // intentionally independent from order_date for collection-on-delivery.
        $receipts = OrderPayment::query()
            ->where('created_at', '>=', $startsAt)
            ->where('created_at', '<', $endsAt)
            ->whereIn('method', ['cash', 'transfer'])
            ->whereHas('order', fn ($query) => $query
                ->where('branch_id', $branchId)
                ->whereNotIn('status', ['draft', 'pending_payment', 'cancelled'])
                ->where('courtesy', false))
            ->get(['order_id', 'method', 'amount']);
        $receiptTotals = $receipts->groupBy('method')->map(fn (Collection $rows): float => (float) $rows->sum('amount'));

        // Method classification is based on all payments for sales created on the
        // report day, while the cash balance above remains based on receipt time.
        $salePayments = OrderPayment::query()
            ->whereIn('order_id', $paidOrders->pluck('id'))
            ->whereIn('method', ['cash', 'transfer'])
            ->get(['order_id', 'method', 'amount'])
            ->groupBy('order_id');
        $cashOnlyOrders = collect();
        $transferOnlyOrders = collect();
        $mixedOrders = collect();
        $uncollectedOrders = collect();
        foreach ($paidOrders as $order) {
            $methods = $salePayments->get($order->id, collect())->pluck('method')->unique();
            if ($methods->contains('cash') && $methods->contains('transfer')) {
                $mixedOrders->push($order);
            } elseif ($methods->contains('cash')) {
                $cashOnlyOrders->push($order);
            } elseif ($methods->contains('transfer')) {
                $transferOnlyOrders->push($order);
            } else {
                $uncollectedOrders->push($order);
            }
        }

        $cashPurchases = (float) Purchase::query()
            ->where('branch_id', $branchId)
            ->whereDate('purchased_at', $localDate)
            ->where('payment_source', 'cash')
            ->sum('total');
        $day = CashDay::with('movements')
            ->where('branch_id', $branchId)
            ->whereDate('date', $localDate)
            ->first();
        $income = (float) ($day?->movements->where('type', 'income')->sum('amount') ?? 0);
        $expense = (float) ($day?->movements->where('type', 'expense')->sum('amount') ?? 0);
        $cash = (float) $receiptTotals->get('cash', 0);
        $transfer = (float) $receiptTotals->get('transfer', 0);
        $openingAmount = (float) ($day?->opening_amount ?? 0);
        $expectedCash = $openingAmount + $cash + $income - $expense - $cashPurchases;

        return [
            'date' => $localDate,
            'cash_day_id' => $day?->id,
            'closed_at' => $day?->closed_at?->toISOString(),
            'opening_amount' => $openingAmount,
            'expected_amount' => $day?->expected_amount === null ? null : (float) $day->expected_amount,
            'actual_amount' => $day?->actual_amount === null ? null : (float) $day->actual_amount,
            'difference' => $day?->difference === null ? null : (float) $day->difference,
            'calculated_difference' => $day?->actual_amount === null ? null : (float) $day->actual_amount - $expectedCash,
            'orders' => $accountedOrders->count(),
            'gross_sales' => (float) $paidOrders->sum('total'),
            'cash' => $cash,
            'transfer' => $transfer,
            'cash_only_orders' => $cashOnlyOrders->count(),
            'cash_only_sales' => (float) $cashOnlyOrders->sum('total'),
            'transfer_only_orders' => $transferOnlyOrders->count(),
            'transfer_only_sales' => (float) $transferOnlyOrders->sum('total'),
            'mixed_orders' => $mixedOrders->count(),
            'mixed_sales' => (float) $mixedOrders->sum('total'),
            'uncollected_orders' => $uncollectedOrders->count(),
            'uncollected_sales' => (float) $uncollectedOrders->sum('total'),
            'courtesy_orders' => $courtesyOrders->count(),
            'courtesy' => (float) $courtesyOrders->sum('total'),
            'discounts' => (float) $accountedOrders->sum('discount'),
            'paid_discounts' => (float) $paidOrders->sum('discount'),
            'courtesy_discounts' => (float) $courtesyOrders->sum('discount'),
            'cancelled' => $orders->where('status', 'cancelled')->count(),
            'cancelled_total' => (float) $orders->where('status', 'cancelled')->sum('total'),
            'scheduled' => $accountedOrders->whereNotNull('scheduled_at')->count(),
            'scheduled_total' => (float) $accountedOrders->whereNotNull('scheduled_at')->where('courtesy', false)->sum('total'),
            'cash_purchases' => $cashPurchases,
            'other_income' => $income,
            'other_expenses' => $expense,
            'total_cash_outflows' => $expense + $cashPurchases,
            'expected_cash' => $expectedCash,
        ];
    }
}
