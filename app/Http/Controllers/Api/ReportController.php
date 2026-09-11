<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Ingredient;
use App\Models\InventoryAdjustment;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Purchase;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    private const NON_ACCOUNTED_STATUSES = ['draft', 'pending_payment', 'cancelled'];

    private const WASTE_REASONS = ['waste', 'expiry', 'preparation_error', 'loss'];

    public function sales(Request $request)
    {
        $filters = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'type' => 'nullable|in:pickup,whatsapp,delivery,dine_in',
            'payment_method' => 'nullable|in:cash,transfer,mixed,courtesy',
            'customer_id' => 'nullable|integer',
            'product_id' => 'nullable|integer',
            'category_id' => 'nullable|integer',
        ]);
        $filters = $this->boundedFilters($request, $filters);
        $base = $this->orderQuery($request, $filters);
        $accounted = (clone $base)->whereNotIn('status', self::NON_ACCOUNTED_STATUSES);
        $cancelled = (clone $base)->where('status', 'cancelled');

        $summary = (clone $accounted)
            ->selectRaw('COUNT(*) AS orders')
            ->selectRaw('COALESCE(SUM(CASE WHEN courtesy = 0 THEN total ELSE 0 END), 0) AS sales')
            ->selectRaw('COALESCE(SUM(discount), 0) AS discounts')
            ->selectRaw('COALESCE(SUM(CASE WHEN courtesy = 1 THEN 1 ELSE 0 END), 0) AS courtesy_orders')
            ->selectRaw('COALESCE(SUM(CASE WHEN courtesy = 1 THEN total ELSE 0 END), 0) AS courtesy_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN scheduled_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS scheduled_orders')
            ->first();
        $cancelledSummary = (clone $cancelled)
            ->selectRaw('COUNT(*) AS orders')
            ->selectRaw('COALESCE(SUM(total), 0) AS total')
            ->selectRaw('COALESCE(SUM(discount), 0) AS discounts')
            ->first();

        $days = (clone $accounted)
            ->selectRaw('order_date, COUNT(*) AS orders')
            ->selectRaw('COALESCE(SUM(CASE WHEN courtesy = 0 THEN total ELSE 0 END), 0) AS total')
            ->selectRaw('COALESCE(SUM(discount), 0) AS discounts')
            ->selectRaw('COALESCE(SUM(CASE WHEN courtesy = 1 THEN 1 ELSE 0 END), 0) AS courtesy_orders')
            ->selectRaw('COALESCE(SUM(CASE WHEN courtesy = 1 THEN total ELSE 0 END), 0) AS courtesy_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN scheduled_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS scheduled_orders')
            ->groupBy('order_date')
            ->get()
            ->keyBy(fn (Order $order) => $order->order_date->toDateString());
        $cancelledDays = (clone $cancelled)
            ->selectRaw('order_date, COUNT(*) AS cancelled_orders, COALESCE(SUM(total), 0) AS cancelled_total')
            ->groupBy('order_date')
            ->get()
            ->keyBy(fn (Order $order) => $order->order_date->toDateString());
        $byDay = $days->keys()
            ->merge($cancelledDays->keys())
            ->unique()
            ->sort()
            ->map(function (string $date) use ($days, $cancelledDays): array {
                $day = $days->get($date);
                $cancelledDay = $cancelledDays->get($date);

                return [
                    'order_date' => $date,
                    'orders' => (int) ($day?->orders ?? 0),
                    'total' => (float) ($day?->total ?? 0),
                    'discounts' => (float) ($day?->discounts ?? 0),
                    'courtesy_orders' => (int) ($day?->courtesy_orders ?? 0),
                    'courtesy_total' => (float) ($day?->courtesy_total ?? 0),
                    'scheduled_orders' => (int) ($day?->scheduled_orders ?? 0),
                    'cancelled_orders' => (int) ($cancelledDay?->cancelled_orders ?? 0),
                    'cancelled_total' => (float) ($cancelledDay?->cancelled_total ?? 0),
                ];
            })
            ->values();

        $scheduled = (clone $accounted)
            ->with(['customer' => fn ($query) => $query->where('branch_id', $request->user()->branch_id)])
            ->whereNotNull('scheduled_at')
            ->orderBy('scheduled_at')
            ->get(['id', 'customer_id', 'order_date', 'daily_number', 'status', 'type', 'scheduled_at', 'total', 'courtesy']);
        $cancelledRows = (clone $cancelled)
            ->with(['histories' => fn ($query) => $query->where('to_status', 'cancelled')->latest('id')])
            ->orderByDesc('order_date')
            ->orderByDesc('daily_number')
            ->get(['id', 'order_date', 'daily_number', 'status', 'type', 'scheduled_at', 'total', 'discount', 'courtesy'])
            ->map(fn (Order $order): array => [
                'id' => $order->id,
                'order_date' => $order->order_date->toDateString(),
                'daily_number' => $order->daily_number,
                'type' => $order->type,
                'scheduled_at' => $order->scheduled_at?->toISOString(),
                'total' => (float) $order->total,
                'discount' => (float) $order->discount,
                'courtesy' => $order->courtesy,
                'reason' => $order->histories->first()?->comment,
            ]);

        return [
            'summary' => [
                'orders' => (int) ($summary?->orders ?? 0),
                'sales' => (float) ($summary?->sales ?? 0),
                'discounts' => (float) ($summary?->discounts ?? 0),
                'courtesy_orders' => (int) ($summary?->courtesy_orders ?? 0),
                'courtesy_total' => (float) ($summary?->courtesy_total ?? 0),
                'cancelled_orders' => (int) ($cancelledSummary?->orders ?? 0),
                'cancelled_total' => (float) ($cancelledSummary?->total ?? 0),
                'cancelled_discounts' => (float) ($cancelledSummary?->discounts ?? 0),
                'scheduled_orders' => (int) ($summary?->scheduled_orders ?? 0),
            ],
            'by_day' => $byDay,
            'scheduled' => $scheduled,
            'cancelled' => $cancelledRows,
        ];
    }

    public function products(Request $request)
    {
        $filters = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'product_id' => 'nullable|integer',
            'category_id' => 'nullable|integer',
            'customer_id' => 'nullable|integer',
            'type' => 'nullable|in:pickup,whatsapp,delivery,dine_in',
            'payment_method' => 'nullable|in:cash,transfer,mixed,courtesy',
            'group_by' => 'nullable|in:product,category',
        ]);
        $filters = $this->boundedFilters($request, $filters);
        $branchId = (int) $request->user()->branch_id;
        $orders = $this->orderQuery($request, $filters)
            ->whereNotIn('status', self::NON_ACCOUNTED_STATUSES);
        $byCategory = ($filters['group_by'] ?? 'product') === 'category';
        $groupColumns = $byCategory
            ? [
                DB::raw("CASE WHEN order_items.combo_id IS NOT NULL THEN 'Paquetes' ELSE COALESCE(product_categories.name, 'Sin categoría') END"),
                DB::raw('CASE WHEN order_items.combo_id IS NOT NULL THEN NULL ELSE product_categories.id END'),
            ]
            : ['order_items.combo_id', 'order_items.product_variant_id', 'order_items.name', 'products.id', 'product_categories.id', 'product_categories.name'];
        $discount = 'CASE WHEN orders.subtotal > 0 THEN (CASE WHEN orders.discount < 0 THEN 0 WHEN orders.discount > orders.subtotal THEN orders.subtotal ELSE orders.discount END) * (order_items.total / orders.subtotal) ELSE 0 END';
        $net = "CASE WHEN order_items.total - ({$discount}) > 0 THEN order_items.total - ({$discount}) ELSE 0 END";

        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'order_items.product_variant_id')
            ->leftJoin('products', function ($join) use ($branchId): void {
                $join->on('products.id', '=', 'product_variants.product_id')->where('products.branch_id', $branchId);
            })
            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.product_category_id')
            ->whereIn('orders.id', (clone $orders)->select('id'))
            ->when($filters['product_id'] ?? null, fn ($query, $id) => $query->where('products.id', $id))
            ->when($filters['category_id'] ?? null, fn ($query, $id) => $query->where('products.product_category_id', $id))
            ->selectRaw($byCategory
                ? "CASE WHEN order_items.combo_id IS NOT NULL THEN 'Paquetes' ELSE COALESCE(product_categories.name, 'Sin categoría') END AS name,
                   CASE WHEN order_items.combo_id IS NOT NULL THEN NULL ELSE product_categories.id END AS category_id,
                   CASE WHEN order_items.combo_id IS NOT NULL THEN 'Paquetes' ELSE COALESCE(product_categories.name, 'Sin categoría') END AS category"
                : "order_items.name AS product, order_items.name, products.id AS product_id,
                   CASE WHEN products.id IS NULL THEN NULL ELSE order_items.product_variant_id END AS variant_id,
                   order_items.combo_id, product_categories.id AS category_id,
                   CASE WHEN order_items.combo_id IS NOT NULL THEN 'Paquetes' ELSE COALESCE(product_categories.name, 'Sin categoría') END AS category")
            ->selectRaw('SUM(order_items.quantity) AS quantity')
            ->selectRaw('SUM(CASE WHEN orders.courtesy = 0 THEN order_items.quantity ELSE 0 END) AS paid_quantity')
            ->selectRaw('SUM(CASE WHEN orders.courtesy = 1 THEN order_items.quantity ELSE 0 END) AS courtesy_quantity')
            ->selectRaw('SUM(CASE WHEN orders.courtesy = 0 THEN order_items.total ELSE 0 END) AS gross_sales')
            ->selectRaw("SUM(CASE WHEN orders.courtesy = 0 THEN {$discount} ELSE 0 END) AS discounts")
            ->selectRaw("SUM(CASE WHEN orders.courtesy = 0 THEN {$net} ELSE 0 END) AS sales")
            ->selectRaw("SUM(CASE WHEN orders.courtesy = 1 THEN {$net} ELSE 0 END) AS courtesy_total")
            ->groupBy($groupColumns)
            ->orderByDesc('quantity')
            ->get();

        return $rows->map(function ($row): array {
            $result = (array) $row;
            foreach (['quantity', 'paid_quantity', 'courtesy_quantity', 'gross_sales', 'discounts', 'sales', 'courtesy_total'] as $field) {
                $result[$field] = round((float) $result[$field], 2);
            }
            foreach (['product_id', 'variant_id', 'combo_id', 'category_id'] as $field) {
                if (array_key_exists($field, $result)) {
                    $result[$field] = $result[$field] === null ? null : (int) $result[$field];
                }
            }

            return $result;
        });
    }

    public function inventory(Request $request)
    {
        $filters = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'ingredient_id' => 'nullable|integer',
        ]);
        $filters = $this->boundedFilters($request, $filters);
        $branchId = (int) $request->user()->branch_id;
        $timezone = $this->timezone($request);
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $ingredients = Ingredient::with('baseUnit')
            ->where('branch_id', $branchId)
            ->when($filters['ingredient_id'] ?? null, fn (Builder $query, $id) => $query->whereKey($id))
            ->orderBy('name')
            ->get();
        $ingredientIds = $ingredients->pluck('id');
        $batches = InventoryBatch::query()
            ->where('branch_id', $branchId)
            ->whereIn('ingredient_id', $ingredientIds)
            ->get();
        $batchesByIngredient = $batches->groupBy('ingredient_id');
        $movements = InventoryMovement::query()
            ->where('inventory_movements.branch_id', $branchId)
            ->whereIn('inventory_movements.ingredient_id', $ingredientIds)
            ->whereIn('inventory_movements.type', ['sale', 'production_input', 'return'])
            ->leftJoin('inventory_batches', 'inventory_batches.id', '=', 'inventory_movements.inventory_batch_id');
        $this->timestampRange($movements, $filters, 'inventory_movements.created_at', $timezone);
        $consumption = $movements
            ->select('inventory_movements.ingredient_id')
            ->selectRaw("SUM(CASE WHEN inventory_movements.type IN ('sale', 'return') THEN inventory_movements.quantity ELSE 0 END) AS sale_net")
            ->selectRaw("SUM(CASE WHEN inventory_movements.type = 'production_input' THEN inventory_movements.quantity ELSE 0 END) AS production_net")
            ->selectRaw("SUM(CASE WHEN inventory_movements.type = 'return' THEN inventory_movements.quantity ELSE 0 END) AS returned_quantity")
            ->selectRaw("SUM(CASE WHEN inventory_movements.type IN ('sale', 'return') THEN inventory_movements.quantity * COALESCE(inventory_batches.unit_cost, 0) ELSE 0 END) AS sale_cost_net")
            ->selectRaw("SUM(CASE WHEN inventory_movements.type = 'production_input' THEN inventory_movements.quantity * COALESCE(inventory_batches.unit_cost, 0) ELSE 0 END) AS production_cost_net")
            ->groupBy('inventory_movements.ingredient_id')
            ->get()
            ->map(function ($row) use ($ingredients): array {
                $ingredientId = (int) $row->ingredient_id;
                $ingredient = $ingredients->firstWhere('id', $ingredientId);
                $saleQuantity = max(0, -(float) $row->sale_net);
                $productionQuantity = max(0, -(float) $row->production_net);
                $saleCost = max(0, -(float) $row->sale_cost_net);
                $productionCost = max(0, -(float) $row->production_cost_net);

                return [
                    'ingredient_id' => $ingredientId,
                    'name' => $ingredient?->name,
                    'unit' => $ingredient?->baseUnit?->symbol,
                    'sale_quantity' => round($saleQuantity, 4),
                    'production_quantity' => round($productionQuantity, 4),
                    'returned_quantity' => round(max(0, (float) $row->returned_quantity), 4),
                    'total_quantity' => round($saleQuantity + $productionQuantity, 4),
                    'estimated_cost' => round($saleCost + $productionCost, 2),
                ];
            })
            ->sortByDesc('total_quantity')
            ->values();

        $adjustments = InventoryAdjustment::query()
            ->where('inventory_adjustments.branch_id', $branchId)
            ->whereIn('inventory_adjustments.ingredient_id', $ingredientIds)
            ->whereIn('inventory_adjustments.reason', self::WASTE_REASONS)
            ->leftJoin('inventory_batches', 'inventory_batches.id', '=', 'inventory_adjustments.inventory_batch_id');
        $this->timestampRange($adjustments, $filters, 'inventory_adjustments.created_at', $timezone);
        $wasteByReason = (clone $adjustments)
            ->select('inventory_adjustments.reason')
            ->selectRaw('COUNT(*) AS adjustments_count')
            ->selectRaw('SUM(ABS(inventory_adjustments.quantity)) AS quantity')
            ->selectRaw('SUM(ABS(inventory_adjustments.quantity) * COALESCE(inventory_batches.unit_cost, 0)) AS estimated_cost')
            ->groupBy('inventory_adjustments.reason')
            ->get()
            ->map(fn ($row): array => [
                'reason' => $row->reason,
                'quantity' => round((float) $row->quantity, 4),
                'estimated_cost' => round((float) $row->estimated_cost, 2),
                '_count' => (int) $row->adjustments_count,
            ]);
        $wasteTotal = (float) $wasteByReason->sum('quantity');
        $wasteAdjustmentCount = (int) $wasteByReason->sum('_count');
        $wasteByReason = $wasteByReason->map(function (array $row): array {
            unset($row['_count']);

            return $row;
        })->values();
        $wasteByIngredient = (clone $adjustments)
            ->select('inventory_adjustments.ingredient_id')
            ->selectRaw('SUM(ABS(inventory_adjustments.quantity)) AS quantity')
            ->selectRaw('SUM(ABS(inventory_adjustments.quantity) * COALESCE(inventory_batches.unit_cost, 0)) AS estimated_cost')
            ->groupBy('inventory_adjustments.ingredient_id')
            ->get()
            ->map(function ($row) use ($ingredients): array {
                $ingredientId = (int) $row->ingredient_id;
                $ingredient = $ingredients->firstWhere('id', $ingredientId);

                return [
                    'ingredient_id' => $ingredientId,
                    'name' => $ingredient?->name,
                    'unit' => $ingredient?->baseUnit?->symbol,
                    'quantity' => round((float) $row->quantity, 4),
                    'estimated_cost' => round((float) $row->estimated_cost, 2),
                ];
            })
            ->sortByDesc('quantity')
            ->values();

        $ingredientRows = $ingredients->map(function (Ingredient $ingredient) use ($batchesByIngredient, $consumption, $wasteByIngredient, $today): array {
            $rows = $batchesByIngredient->get($ingredient->id, collect());
            $usable = $rows->filter(fn (InventoryBatch $batch) => (float) $batch->available_quantity > 0
                && (! $batch->expires_at || $batch->expires_at->toDateString() >= $today->toDateString()));
            $expired = $rows->filter(fn (InventoryBatch $batch) => (float) $batch->available_quantity > 0
                && $batch->expires_at
                && $batch->expires_at->toDateString() < $today->toDateString());
            $expiringLimit = $today->addDays($ingredient->expiry_alert_days);
            $expiring = $usable->filter(fn (InventoryBatch $batch) => $batch->expires_at
                && $batch->expires_at->toDateString() <= $expiringLimit->toDateString());
            $stock = (float) $usable->sum('available_quantity');
            $status = $stock <= (float) $ingredient->critical_stock
                ? 'critical'
                : ($stock <= (float) $ingredient->minimum_stock ? 'low' : 'ok');
            $row = $ingredient->setAppends([])->toArray();
            $row['current_stock'] = number_format($stock, 4, '.', '');
            $row['usable_stock'] = round($stock, 4);
            $row['expired_stock'] = round((float) $expired->sum('available_quantity'), 4);
            $row['expiring_stock'] = round((float) $expiring->sum('available_quantity'), 4);
            $row['stock_status'] = $status;
            $row['consumed_quantity'] = (float) ($consumption->firstWhere('ingredient_id', $ingredient->id)['total_quantity'] ?? 0);
            $row['waste_quantity'] = (float) ($wasteByIngredient->firstWhere('ingredient_id', $ingredient->id)['quantity'] ?? 0);

            return $row;
        });
        $expiringBatches = $batches->filter(function (InventoryBatch $batch) use ($ingredients, $today): bool {
            $ingredient = $ingredients->firstWhere('id', $batch->ingredient_id);

            return (float) $batch->available_quantity > 0
                && $batch->expires_at
                && $batch->expires_at->toDateString() >= $today->toDateString()
                && $batch->expires_at->toDateString() <= $today->addDays($ingredient?->expiry_alert_days ?? 0)->toDateString();
        });
        $expiredBatches = $batches->filter(fn (InventoryBatch $batch) => (float) $batch->available_quantity > 0
            && $batch->expires_at
            && $batch->expires_at->toDateString() < $today->toDateString());
        $batchRow = function (InventoryBatch $batch) use ($ingredients): array {
            $ingredient = $ingredients->firstWhere('id', $batch->ingredient_id);

            return [
                'id' => $batch->id,
                'ingredient_id' => $batch->ingredient_id,
                'ingredient' => $ingredient?->name,
                'lot_code' => $batch->lot_code,
                'expires_at' => $batch->expires_at?->toDateString(),
                'available_quantity' => (float) $batch->available_quantity,
                'unit' => $ingredient?->baseUnit?->symbol,
                'estimated_value' => round((float) $batch->available_quantity * (float) $batch->unit_cost, 2),
            ];
        };

        return [
            'summary' => [
                'ingredients' => $ingredientRows->count(),
                'low_stock' => $ingredientRows->where('stock_status', 'low')->count(),
                'critical_stock' => $ingredientRows->where('stock_status', 'critical')->count(),
                'expiring_batches' => $expiringBatches->count(),
                'expired_batches' => $expiredBatches->count(),
                'consumed_ingredients' => $consumption->where('total_quantity', '>', 0)->count(),
                'waste_adjustments' => $wasteAdjustmentCount,
                'waste_ingredients' => $wasteByIngredient->count(),
                'consumed_quantity' => round((float) $consumption->sum('total_quantity'), 4),
                'waste_quantity' => round($wasteTotal, 4),
            ],
            'ingredients' => $ingredientRows->values(),
            'consumption' => $consumption,
            'low_stock' => $ingredientRows->whereIn('stock_status', ['low', 'critical'])->values(),
            'expiring' => $expiringBatches->sortBy('expires_at')->map($batchRow)->values(),
            'expired' => $expiredBatches->sortBy('expires_at')->map($batchRow)->values(),
            'waste' => round($wasteTotal, 4),
            'waste_by_reason' => $wasteByReason,
            'waste_by_ingredient' => $wasteByIngredient,
        ];
    }

    public function purchases(Request $request)
    {
        $filters = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'supplier_id' => 'nullable|integer',
            'ingredient_id' => 'nullable|integer',
            'payment_source' => 'nullable|in:cash,owner,bank,credit,other',
            'include_summary' => 'nullable|boolean',
        ]);
        $filters = $this->boundedFilters($request, $filters);
        $branchId = (int) $request->user()->branch_id;
        $query = Purchase::with([
            'supplier' => fn ($supplier) => $supplier->where('branch_id', $branchId),
            'items.ingredient' => fn ($ingredient) => $ingredient->where('branch_id', $branchId),
        ])
            ->where('branch_id', $branchId)
            ->when($filters['supplier_id'] ?? null, fn (Builder $builder, $id) => $builder->where('supplier_id', $id))
            ->when($filters['ingredient_id'] ?? null, fn (Builder $builder, $id) => $builder->whereHas(
                'items.ingredient',
                fn (Builder $ingredient) => $ingredient->where('branch_id', $branchId)->whereKey($id),
            ))
            ->when($filters['payment_source'] ?? null, fn (Builder $builder, $source) => $builder->where('payment_source', $source));
        $this->dateRange($query, $filters, 'purchased_at');
        $purchases = $query->orderByDesc('purchased_at')->orderByDesc('id')->get();

        if (! ($filters['include_summary'] ?? false)) {
            return $purchases;
        }

        return [
            'summary' => [
                'purchases' => $purchases->count(),
                'items' => $purchases->sum(fn (Purchase $purchase) => $purchase->items->count()),
                'total' => round((float) $purchases->sum('total'), 2),
                'cash_impact' => round((float) $purchases->where('payment_source', 'cash')->sum('total'), 2),
            ],
            'by_payment_source' => $purchases->groupBy('payment_source')->map(fn (Collection $rows, string $source): array => [
                'payment_source' => $source,
                'purchases' => $rows->count(),
                'total' => round((float) $rows->sum('total'), 2),
            ])->values(),
            'by_supplier' => $purchases->groupBy(fn (Purchase $purchase) => (string) ($purchase->supplier_id ?? 'none'))->map(fn (Collection $rows): array => [
                'supplier_id' => $rows->first()->supplier_id,
                'supplier' => $rows->first()->supplier?->name ?? 'Sin proveedor',
                'purchases' => $rows->count(),
                'total' => round((float) $rows->sum('total'), 2),
            ])->sortByDesc('total')->values(),
            'by_day' => $purchases->groupBy(fn (Purchase $purchase) => $purchase->purchased_at->toDateString())->map(fn (Collection $rows, string $date): array => [
                'date' => $date,
                'purchases' => $rows->count(),
                'total' => round((float) $rows->sum('total'), 2),
            ])->sortBy('date')->values(),
            'purchases' => $purchases,
        ];
    }

    public function customers(Request $request)
    {
        $filters = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'customer_id' => 'nullable|integer',
        ]);
        $filters = $this->boundedFilters($request, $filters);
        $branchId = (int) $request->user()->branch_id;
        $timezone = $this->timezone($request);
        $accountedOrders = function (Builder $query) use ($branchId, $filters): void {
            $query->where('branch_id', $branchId)->whereNotIn('status', self::NON_ACCOUNTED_STATUSES);
            $this->dateRange($query, $filters, 'order_date');
        };
        $paidOrders = function (Builder $query) use ($accountedOrders): void {
            $accountedOrders($query);
            $query->where('courtesy', false);
        };
        $courtesyOrders = function (Builder $query) use ($accountedOrders): void {
            $accountedOrders($query);
            $query->where('courtesy', true);
        };
        $completedOrders = function (Builder $query) use ($branchId, $filters): void {
            $query->where('branch_id', $branchId)->where('status', 'delivered');
            $this->dateRange($query, $filters, 'order_date');
        };
        $loyalty = function (Builder $query) use ($filters, $timezone): void {
            $this->timestampRange($query, $filters, 'created_at', $timezone);
        };
        $earned = function (Builder $query) use ($loyalty): void {
            $loyalty($query);
            $query->where('type', 'earned')->whereDoesntHave('reversal');
        };
        $reversedEarned = function (Builder $query) use ($filters, $timezone): void {
            $query->where('type', 'earned')->whereHas('reversal', function (Builder $reversal) use ($filters, $timezone): void {
                $this->timestampRange($reversal, $filters, 'created_at', $timezone);
            });
        };
        $expired = function (Builder $query) use ($loyalty): void {
            $loyalty($query);
            $query->where('type', 'expired');
        };
        $adjusted = function (Builder $query) use ($loyalty): void {
            $loyalty($query);
            $query->where('type', 'adjustment');
        };
        $activeRedemptions = function (Builder $query) use ($filters, $timezone): void {
            $query->whereNull('cancelled_at');
            $this->timestampRange($query, $filters, 'created_at', $timezone);
        };
        $cancelledRedemptions = function (Builder $query) use ($filters, $timezone): void {
            $query->whereNotNull('cancelled_at');
            $this->timestampRange($query, $filters, 'cancelled_at', $timezone);
        };

        return Customer::query()
            ->where('branch_id', $branchId)
            ->when($filters['customer_id'] ?? null, fn (Builder $query, $id) => $query->whereKey($id))
            ->withCount([
                'orders as orders_count' => $accountedOrders,
                'orders as completed_orders_count' => $completedOrders,
                'orders as courtesy_orders_count' => $courtesyOrders,
            ])
            ->withSum(['orders as orders_total' => $paidOrders], 'total')
            ->withSum(['orders as courtesy_total' => $courtesyOrders], 'total')
            ->withMax(['orders as last_order_date' => $accountedOrders], 'order_date')
            ->withSum('loyaltyTransactions as points_balance', 'points')
            ->withSum(['loyaltyTransactions as points_generated' => $earned], 'points')
            ->withSum(['loyaltyTransactions as reversed_points_generated' => $reversedEarned], 'points')
            ->withSum(['loyaltyTransactions as points_expired' => $expired], 'points')
            ->withSum(['loyaltyTransactions as points_adjusted' => $adjusted], 'points')
            ->withSum(['loyaltyRedemptions as points_redeemed' => $activeRedemptions], 'points')
            ->withSum(['loyaltyRedemptions as redemption_value' => $activeRedemptions], 'value')
            ->withSum(['loyaltyRedemptions as reversed_points_redemptions' => $cancelledRedemptions], 'points')
            ->withSum(['loyaltyRedemptions as reversed_redemption_value' => $cancelledRedemptions], 'value')
            ->orderByDesc('orders_count')
            ->orderBy('name')
            ->get()
            ->map(function (Customer $customer): Customer {
                $customer->setAttribute('orders_total', (float) ($customer->orders_total ?? 0));
                $customer->setAttribute('courtesy_total', (float) ($customer->courtesy_total ?? 0));
                $customer->setAttribute('points_balance', (float) ($customer->points_balance ?? 0));
                $customer->setAttribute('points_generated', (float) ($customer->points_generated ?? 0));
                $customer->setAttribute('reversed_points_generated', (float) ($customer->reversed_points_generated ?? 0));
                $customer->setAttribute('points_redeemed', (float) ($customer->points_redeemed ?? 0));
                $customer->setAttribute('redemption_value', (float) ($customer->redemption_value ?? 0));
                $customer->setAttribute('reversed_points_redemptions', (float) ($customer->reversed_points_redemptions ?? 0));
                $customer->setAttribute('reversed_redemption_value', (float) ($customer->reversed_redemption_value ?? 0));
                $customer->setAttribute('points_expired', abs((float) ($customer->points_expired ?? 0)));
                $customer->setAttribute('points_adjusted', (float) ($customer->points_adjusted ?? 0));

                return $customer;
            });
    }

    public function profit(Request $request)
    {
        $filters = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'product_id' => 'nullable|integer',
            'category_id' => 'nullable|integer',
            'customer_id' => 'nullable|integer',
            'type' => 'nullable|in:pickup,whatsapp,delivery,dine_in',
        ]);
        $filters = $this->boundedFilters($request, $filters);
        $branchId = (int) $request->user()->branch_id;
        $orders = $this->orderQuery($request, $filters)
            ->whereNotIn('status', self::NON_ACCOUNTED_STATUSES);
        $items = OrderItem::query()
            ->with(['order', 'variant.product.category', 'ingredients'])
            ->whereHas('order', fn (Builder $query) => $query->whereIn('id', (clone $orders)->select('id')))
            ->when($filters['product_id'] ?? null, fn (Builder $query, $id) => $query->whereHas(
                'variant.product',
                fn (Builder $products) => $products->where('branch_id', $branchId)->whereKey($id),
            ))
            ->when($filters['category_id'] ?? null, fn (Builder $query, $id) => $query->whereHas(
                'variant.product',
                fn (Builder $products) => $products->where('branch_id', $branchId)->where('product_category_id', $id),
            ))
            ->get();
        $ingredientIds = $items->flatMap(fn (OrderItem $item) => $item->ingredients->pluck('ingredient_id'))->unique();
        $batchCosts = InventoryBatch::query()
            ->where('branch_id', $branchId)
            ->whereIn('ingredient_id', $ingredientIds)
            ->where('initial_quantity', '>', 0)
            ->select('ingredient_id')
            ->selectRaw('SUM(initial_quantity * unit_cost) / SUM(initial_quantity) AS weighted_cost')
            ->groupBy('ingredient_id')
            ->get()
            ->mapWithKeys(fn (InventoryBatch $batch) => [$batch->ingredient_id => (float) $batch->weighted_cost]);

        $rows = [];
        foreach ($items as $item) {
            $identity = $this->productIdentity($item, $branchId);
            $key = $identity['key'];
            $rows[$key] ??= $identity + [
                'quantity' => 0.0,
                'paid_quantity' => 0.0,
                'courtesy_quantity' => 0.0,
                'gross_sales' => 0.0,
                'discounts' => 0.0,
                'sales' => 0.0,
                'courtesy_value' => 0.0,
                'estimated_cost' => 0.0,
                'paid_estimated_cost' => 0.0,
                'courtesy_estimated_cost' => 0.0,
                'missing_cost_ingredient_ids' => [],
            ];
            $cost = 0.0;
            foreach ($item->ingredients as $requirement) {
                if (! $batchCosts->has($requirement->ingredient_id)) {
                    $rows[$key]['missing_cost_ingredient_ids'][] = $requirement->ingredient_id;
                }
                $cost += (float) $requirement->quantity * (float) $batchCosts->get($requirement->ingredient_id, 0);
            }
            $discount = $this->allocatedDiscount($item);
            $courtesy = (bool) $item->order->courtesy;
            $rows[$key]['quantity'] += (float) $item->quantity;
            $rows[$key]['estimated_cost'] += $cost;
            if ($courtesy) {
                $rows[$key]['courtesy_quantity'] += (float) $item->quantity;
                $rows[$key]['courtesy_value'] += max(0, (float) $item->total - $discount);
                $rows[$key]['courtesy_estimated_cost'] += $cost;
            } else {
                $rows[$key]['paid_quantity'] += (float) $item->quantity;
                $rows[$key]['gross_sales'] += (float) $item->total;
                $rows[$key]['discounts'] += $discount;
                $rows[$key]['sales'] += max(0, (float) $item->total - $discount);
                $rows[$key]['paid_estimated_cost'] += $cost;
            }
        }

        return collect($rows)->map(function (array $row): array {
            unset($row['key']);
            foreach (['quantity', 'paid_quantity', 'courtesy_quantity'] as $field) {
                $row[$field] = round($row[$field], 2);
            }
            foreach (['gross_sales', 'discounts', 'sales', 'courtesy_value', 'estimated_cost', 'paid_estimated_cost', 'courtesy_estimated_cost'] as $field) {
                $row[$field] = round($row[$field], 2);
            }
            $row['estimated_profit'] = round($row['sales'] - $row['estimated_cost'], 2);
            $row['estimated_margin_percent'] = $row['sales'] > 0
                ? round(($row['estimated_profit'] / $row['sales']) * 100, 2)
                : null;
            $row['missing_cost_ingredient_ids'] = array_values(array_unique($row['missing_cost_ingredient_ids']));

            return $row;
        })->sortByDesc('sales')->values();
    }

    public function times(Request $request)
    {
        $filters = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'type' => 'nullable|in:pickup,whatsapp,delivery,dine_in',
        ]);
        $filters = $this->boundedFilters($request, $filters);
        $orders = Order::query()
            ->leftJoin('order_status_histories AS report_histories', 'report_histories.order_id', '=', 'orders.id')
            ->where('orders.branch_id', $request->user()->branch_id)
            ->whereNotIn('orders.status', self::NON_ACCOUNTED_STATUSES)
            ->when($filters['type'] ?? null, fn (Builder $query, $type) => $query->where('orders.type', $type));
        $this->dateRange($orders, $filters, 'orders.order_date');
        $values = $orders
            ->select(['orders.id', 'orders.daily_number', 'orders.order_date', 'orders.type', 'orders.scheduled_at'])
            ->selectRaw("MIN(CASE WHEN report_histories.to_status = 'confirmed' THEN report_histories.created_at END) AS confirmed_at")
            ->selectRaw("MIN(CASE WHEN report_histories.to_status = 'kitchen_pending' THEN report_histories.created_at END) AS kitchen_pending_at")
            ->selectRaw("MIN(CASE WHEN report_histories.to_status = 'preparing' THEN report_histories.created_at END) AS preparing_at")
            ->selectRaw("MIN(CASE WHEN report_histories.to_status = 'prepared' THEN report_histories.created_at END) AS prepared_at")
            ->selectRaw("MIN(CASE WHEN report_histories.to_status = 'on_way' THEN report_histories.created_at END) AS on_way_at")
            ->selectRaw("MIN(CASE WHEN report_histories.to_status = 'delivered' THEN report_histories.created_at END) AS delivered_at")
            ->groupBy(['orders.id', 'orders.daily_number', 'orders.order_date', 'orders.type', 'orders.scheduled_at'])
            ->havingRaw("MIN(CASE WHEN report_histories.to_status IN ('prepared', 'delivered') THEN 1 END) IS NOT NULL")
            ->get()
            ->map(function (Order $order): array {
                $at = fn (string $status) => $order->getAttribute("{$status}_at")
                    ? CarbonImmutable::parse($order->getAttribute("{$status}_at"))
                    : null;

                return [
                    'order_id' => $order->id,
                    'daily_number' => $order->daily_number,
                    'order_date' => $order->order_date->toDateString(),
                    'type' => $order->type,
                    'scheduled_at' => $order->scheduled_at?->toISOString(),
                    'queue_minutes' => $this->minutesBetween($at('kitchen_pending'), $at('preparing')),
                    'preparation_minutes' => $this->minutesBetween($at('preparing'), $at('prepared')),
                    'kitchen_minutes' => $this->minutesBetween($at('kitchen_pending'), $at('prepared')),
                    'delivery_minutes' => $this->minutesBetween($at('on_way'), $at('delivered')),
                    'fulfillment_minutes' => $this->minutesBetween($at('confirmed'), $at('delivered')),
                    'scheduled_delivery_variance_minutes' => $order->scheduled_at && $at('delivered')
                        ? round($order->scheduled_at->diffInSeconds($at('delivered'), false) / 60, 2)
                        : null,
                ];
            });

        return [
            'orders' => $values,
            'kitchen_samples' => $values->whereNotNull('kitchen_minutes')->count(),
            'delivery_samples' => $values->whereNotNull('delivery_minutes')->count(),
            'average_queue_minutes' => $this->average($values, 'queue_minutes'),
            'average_preparation_minutes' => $this->average($values, 'preparation_minutes'),
            'average_kitchen_minutes' => $this->average($values, 'kitchen_minutes'),
            'average_delivery_minutes' => $this->average($values, 'delivery_minutes'),
            'average_fulfillment_minutes' => $this->average($values, 'fulfillment_minutes'),
        ];
    }

    private function orderQuery(Request $request, array $filters): Builder
    {
        $branchId = (int) $request->user()->branch_id;
        $query = Order::query()
            ->where('branch_id', $branchId)
            ->when($filters['type'] ?? null, fn (Builder $orders, $type) => $orders->where('type', $type))
            ->when($filters['customer_id'] ?? null, fn (Builder $orders, $id) => $orders->where('customer_id', $id))
            ->when($filters['product_id'] ?? null, fn (Builder $orders, $id) => $orders->whereHas(
                'items.variant.product',
                fn (Builder $products) => $products->where('branch_id', $branchId)->whereKey($id),
            ))
            ->when($filters['category_id'] ?? null, fn (Builder $orders, $id) => $orders->whereHas(
                'items.variant.product',
                fn (Builder $products) => $products->where('branch_id', $branchId)->where('product_category_id', $id),
            ));
        $this->paymentMethod($query, $filters['payment_method'] ?? null);
        $this->dateRange($query, $filters, 'order_date');

        return $query;
    }

    private function paymentMethod(Builder $query, ?string $method): void
    {
        if (! $method) {
            return;
        }
        if ($method === 'courtesy') {
            $query->where('courtesy', true);

            return;
        }

        $query->where('courtesy', false);
        if ($method === 'mixed') {
            $query
                ->whereHas('payments', fn (Builder $payments) => $payments->where('method', 'cash'))
                ->whereHas('payments', fn (Builder $payments) => $payments->where('method', 'transfer'));

            return;
        }

        $query->whereHas('payments', fn (Builder $payments) => $payments->where('method', $method));
    }

    private function productRows(Collection $items, int $branchId, bool $byCategory): Collection
    {
        $rows = [];
        foreach ($items as $item) {
            $identity = $this->productIdentity($item, $branchId);
            if ($byCategory) {
                $identity = [
                    'key' => 'category:'.($identity['category_id'] ?? $identity['category']),
                    'name' => $identity['category'],
                    'category_id' => $identity['category_id'],
                    'category' => $identity['category'],
                ];
            }
            $key = $identity['key'];
            $rows[$key] ??= $identity + [
                'quantity' => 0.0,
                'paid_quantity' => 0.0,
                'courtesy_quantity' => 0.0,
                'gross_sales' => 0.0,
                'discounts' => 0.0,
                'sales' => 0.0,
                'courtesy_total' => 0.0,
            ];
            $discount = $this->allocatedDiscount($item);
            $courtesy = (bool) $item->order->courtesy;
            $rows[$key]['quantity'] += (float) $item->quantity;
            if ($courtesy) {
                $rows[$key]['courtesy_quantity'] += (float) $item->quantity;
                $rows[$key]['courtesy_total'] += max(0, (float) $item->total - $discount);
            } else {
                $rows[$key]['paid_quantity'] += (float) $item->quantity;
                $rows[$key]['gross_sales'] += (float) $item->total;
                $rows[$key]['discounts'] += $discount;
                $rows[$key]['sales'] += max(0, (float) $item->total - $discount);
            }
        }

        return collect($rows)->map(function (array $row): array {
            unset($row['key']);
            foreach (['quantity', 'paid_quantity', 'courtesy_quantity'] as $field) {
                $row[$field] = round($row[$field], 2);
            }
            foreach (['gross_sales', 'discounts', 'sales', 'courtesy_total'] as $field) {
                $row[$field] = round($row[$field], 2);
            }

            return $row;
        })->sortByDesc('quantity')->values();
    }

    private function productIdentity(OrderItem $item, int $branchId): array
    {
        $product = $item->variant?->product;
        if ($product?->branch_id !== $branchId) {
            $product = null;
        }
        $category = $product?->category;
        $categoryName = $item->combo_id ? 'Paquetes' : ($category?->name ?? 'Sin categoría');

        return [
            'key' => $item->combo_id ? "combo:{$item->combo_id}:{$item->name}" : "variant:{$item->product_variant_id}:{$item->name}",
            'product' => $item->name,
            'name' => $item->name,
            'product_id' => $product?->id,
            'variant_id' => $product ? $item->product_variant_id : null,
            'combo_id' => $item->combo_id,
            'category_id' => $category?->id,
            'category' => $categoryName,
        ];
    }

    private function allocatedDiscount(OrderItem $item): float
    {
        $subtotal = max(0, (float) $item->order->subtotal);
        if ($subtotal <= 0) {
            return 0.0;
        }
        $discount = min(max(0, (float) $item->order->discount), $subtotal);

        return $discount * ((float) $item->total / $subtotal);
    }

    private function dateRange(Builder $query, array $filters, string $field): void
    {
        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        $query
            ->when($filters['from'] ?? null, fn (Builder $builder, $date) => $sqlite
                ? $builder->whereDate($field, '>=', $date)
                : $builder->where($field, '>=', $date))
            ->when($filters['to'] ?? null, fn (Builder $builder, $date) => $sqlite
                ? $builder->whereDate($field, '<=', $date)
                : $builder->where($field, '<=', $date));
    }

    private function boundedFilters(Request $request, array $filters): array
    {
        $timezone = $this->timezone($request);
        $to = isset($filters['to'])
            ? CarbonImmutable::parse($filters['to'], $timezone)->startOfDay()
            : CarbonImmutable::now($timezone)->startOfDay();
        $from = isset($filters['from'])
            ? CarbonImmutable::parse($filters['from'], $timezone)->startOfDay()
            : $to->subDays(30);

        abort_if($from->diffInDays($to) > 366, 422, 'El rango máximo para reportes es de 366 días.');

        return $filters + ['from' => $from->toDateString(), 'to' => $to->toDateString()];
    }

    private function timestampRange(Builder $query, array $filters, string $field, string $timezone): void
    {
        if ($filters['from'] ?? null) {
            $query->where($field, '>=', CarbonImmutable::parse($filters['from'], $timezone)->startOfDay()->utc());
        }
        if ($filters['to'] ?? null) {
            $query->where($field, '<', CarbonImmutable::parse($filters['to'], $timezone)->addDay()->startOfDay()->utc());
        }
    }

    private function timezone(Request $request): string
    {
        return $request->user()->branch?->timezone ?: config('app.timezone');
    }

    private function minutesBetween(?CarbonInterface $from, ?CarbonInterface $to): ?float
    {
        if (! $from || ! $to || $to->lt($from)) {
            return null;
        }

        return round($from->diffInSeconds($to) / 60, 2);
    }

    private function average(Collection $values, string $field): ?float
    {
        $samples = $values->pluck($field)->filter(fn ($value) => $value !== null);

        return $samples->isEmpty() ? null : round((float) $samples->avg(), 2);
    }
}
