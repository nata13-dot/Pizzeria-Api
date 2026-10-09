<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\Ingredient;
use App\Models\InventoryAdjustment;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\ProductionBatch;
use App\Models\Purchase;
use App\Services\BranchClock;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    private const REMOVAL_REASONS = ['waste', 'expiry', 'preparation_error', 'gift', 'internal_use', 'loss'];

    public function summary(Request $request, BranchClock $clock)
    {
        $rows = $this->inventoryItemsQuery($request, $clock)->where('ingredients.active', true)->get();
        $today = $clock->today($request->user()->branch_id)->toDateString();
        // The per-ingredient alert window cannot be represented by a single fixed date.
        $expiringCount = InventoryBatch::query()
            ->join('ingredients', 'ingredients.id', '=', 'inventory_batches.ingredient_id')
            ->where('inventory_batches.branch_id', $request->user()->branch_id)
            ->where('ingredients.active', true)
            ->where('inventory_batches.available_quantity', '>', 0)
            ->whereNotNull('inventory_batches.expires_at')
            ->whereDate('inventory_batches.expires_at', '>=', $today)
            ->get(['inventory_batches.expires_at', 'ingredients.expiry_alert_days'])
            ->filter(fn ($row) => $row->expires_at->toDateString() <= $clock->today($request->user()->branch_id)->addDays((int) $row->expiry_alert_days)->toDateString())
            ->count();

        return [
            'available' => $rows->where('stock_status', 'available')->count(),
            'low' => $rows->whereIn('stock_status', ['low', 'critical'])->count(),
            'critical' => $rows->where('stock_status', 'critical')->count(),
            'out' => $rows->where('stock_status', 'out')->count(),
            'expiring' => $expiringCount,
        ];
    }

    public function items(Request $request, BranchClock $clock)
    {
        $filters = $request->validate([
            'search' => 'nullable|string|max:100',
            'status' => 'nullable|in:available,low,critical,out',
            'active' => 'nullable|boolean',
            'page' => 'nullable|integer|min:1',
        ]);
        $query = $this->inventoryItemsQuery($request, $clock)
            ->when(isset($filters['search']) && $filters['search'] !== '', fn ($q) => $q->where(fn ($match) => $match
                ->where('ingredients.name', 'like', "%{$filters['search']}%")
                ->orWhere('ingredients.sku', 'like', "%{$filters['search']}%")))
            ->when(isset($filters['active']), fn ($q) => $q->where('ingredients.active', $filters['active']));
        if (isset($filters['status'])) {
            $today = $clock->today($request->user()->branch_id)->toDateString();
            $stock = 'COALESCE((SELECT SUM(available_quantity) FROM inventory_batches WHERE inventory_batches.ingredient_id = ingredients.id AND (expires_at IS NULL OR expires_at >= ?)), 0)';
            match ($filters['status']) {
                'out' => $query->whereRaw("{$stock} <= 0", [$today]),
                'critical' => $query->whereRaw("{$stock} > 0 AND {$stock} <= ingredients.critical_stock", [$today, $today]),
                'low' => $query->whereRaw("{$stock} > ingredients.critical_stock AND {$stock} <= ingredients.minimum_stock", [$today, $today]),
                'available' => $query->whereRaw("{$stock} > ingredients.minimum_stock", [$today]),
            };
        }

        return $query->orderBy('ingredients.name')->orderBy('ingredients.id')->paginate(25);
    }

    public function item(Request $request, Ingredient $ingredient, BranchClock $clock)
    {
        abort_unless($ingredient->branch_id === $request->user()->branch_id, 404);
        $row = $this->inventoryItemsQuery($request, $clock)->whereKey($ingredient->id)->firstOrFail();
        $row->setRelation('presentations', $ingredient->presentations()->with('equivalentUnit')->orderBy('name')->get());
        $row->setAttribute('batches', InventoryBatch::query()->where('branch_id', $ingredient->branch_id)->where('ingredient_id', $ingredient->id)
            ->orderByRaw('expires_at IS NULL')->orderBy('expires_at')->limit(10)->get());
        $row->setAttribute('movements', InventoryMovement::query()->with('batch')->where('branch_id', $ingredient->branch_id)
            ->where('ingredient_id', $ingredient->id)->latest()->limit(10)->get()->map(fn ($movement) => $this->movementPayload($movement)));

        return response()->json($row)->header('Cache-Control', 'no-store');
    }

    public function batches(Request $r)
    {
        $filters = $r->validate(['search' => 'nullable|string|max:100', 'ingredient_id' => 'nullable|integer', 'status' => 'nullable|in:available,expiring,expired,out']);
        $today = app(BranchClock::class)->today($r->user()->branch_id)->toDateString();
        $expiryLimit = DB::connection()->getDriverName() === 'sqlite'
            ? "date(?, '+' || ingredients.expiry_alert_days || ' days')"
            : 'DATE_ADD(?, INTERVAL ingredients.expiry_alert_days DAY)';

        return InventoryBatch::with('ingredient.baseUnit')
            ->join('ingredients', 'ingredients.id', '=', 'inventory_batches.ingredient_id')
            ->select('inventory_batches.*')
            ->where('inventory_batches.branch_id', $r->user()->branch_id)
            ->when($filters['ingredient_id'] ?? null, fn ($q, $id) => $q->where('inventory_batches.ingredient_id', $id))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->whereHas('ingredient', fn ($match) => $match->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")))
            ->when(($filters['status'] ?? null) === 'available', fn ($q) => $q->where('inventory_batches.available_quantity', '>', 0)->where(fn ($dates) => $dates->whereNull('inventory_batches.expires_at')->orWhereDate('inventory_batches.expires_at', '>=', $today)))
            ->when(($filters['status'] ?? null) === 'expiring', fn ($q) => $q->where('inventory_batches.available_quantity', '>', 0)
                ->whereDate('inventory_batches.expires_at', '>=', $today)
                ->whereRaw("inventory_batches.expires_at <= {$expiryLimit}", [$today]))
            ->when(($filters['status'] ?? null) === 'expired', fn ($q) => $q->where('inventory_batches.available_quantity', '>', 0)->whereDate('inventory_batches.expires_at', '<', $today))
            ->when(($filters['status'] ?? null) === 'out', fn ($q) => $q->where('inventory_batches.available_quantity', '<=', 0))
            ->orderByRaw('inventory_batches.expires_at IS NULL')->orderBy('inventory_batches.expires_at')->orderBy('inventory_batches.id')->paginate(30);
    }

    public function movements(Request $r)
    {
        $filters = $r->validate(['search' => 'nullable|string|max:100', 'ingredient_id' => 'nullable|integer', 'type' => 'nullable|string|max:40']);
        $page = InventoryMovement::with(['ingredient.baseUnit', 'batch'])->where('branch_id', $r->user()->branch_id)
            ->when($filters['ingredient_id'] ?? null, fn ($q, $id) => $q->where('ingredient_id', $id))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->whereHas('ingredient', fn ($match) => $match->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")))
            ->latest()->paginate(30);
        $page->setCollection($page->getCollection()->map(fn ($movement) => $this->movementPayload($movement)));

        return $page;
    }

    public function alerts(Request $r, InventoryService $service)
    {
        Ingredient::where('branch_id', $r->user()->branch_id)->each(fn ($i) => $service->refreshAlerts($i));

        return Alert::with(['ingredient', 'batch'])->where('branch_id', $r->user()->branch_id)->whereNull('resolved_at')->latest()->get();
    }

    public function adjust(Request $r, InventoryService $service)
    {
        $data = $r->validate([
            'inventory_batch_id' => 'required|exists:inventory_batches,id',
            'quantity' => 'required|numeric|not_in:0',
            'reason' => 'required|in:waste,expiry,preparation_error,gift,internal_use,manual,loss,initial,correction',
            'comment' => 'nullable|string',
        ]);
        $this->validateDirection($data);

        $adjustment = DB::transaction(function () use ($r, $data, $service) {
            /** @var InventoryBatch $batch */
            $batch = InventoryBatch::query()->lockForUpdate()->findOrFail($data['inventory_batch_id']);
            abort_unless($batch->branch_id === $r->user()->branch_id, 404);

            $adjustment = InventoryAdjustment::create([
                'branch_id' => $batch->branch_id,
                'ingredient_id' => $batch->ingredient_id,
                'inventory_batch_id' => $batch->id,
                'user_id' => $r->user()->id,
                'quantity' => $data['quantity'],
                'reason' => $data['reason'],
                'comment' => $data['comment'] ?? null,
            ]);
            $service->move(
                $batch,
                (float) $data['quantity'],
                in_array($data['reason'], ['waste', 'expiry', 'preparation_error', 'loss'], true) ? 'waste' : 'adjustment',
                $r->user(),
                $adjustment,
                $data['reason'],
                $data['comment'] ?? null,
            );
            $service->refreshAlerts($batch->ingredient);

            return $adjustment;
        });

        return response()->json($adjustment, 201);
    }

    private function validateDirection(array $data): void
    {
        $quantity = (float) $data['quantity'];
        if (in_array($data['reason'], self::REMOVAL_REASONS, true) && $quantity >= 0) {
            throw ValidationException::withMessages([
                'quantity' => 'La cantidad debe ser negativa para una salida, merma, pérdida o caducidad.',
            ]);
        }
        if ($data['reason'] === 'initial' && $quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' => 'La cantidad inicial debe ser positiva.',
            ]);
        }
    }

    private function inventoryItemsQuery(Request $request, BranchClock $clock)
    {
        $today = $clock->today($request->user()->branch_id)->toDateString();
        $stockSql = 'COALESCE((SELECT SUM(available_quantity) FROM inventory_batches WHERE inventory_batches.ingredient_id = ingredients.id AND (expires_at IS NULL OR expires_at >= ?)), 0)';

        return Ingredient::query()->with(['baseUnit', 'type'])
            ->where('ingredients.branch_id', $request->user()->branch_id)
            ->select('ingredients.*')
            ->selectRaw("{$stockSql} AS current_stock", [$today])
            ->selectRaw("CASE WHEN {$stockSql} <= 0 THEN 'out' WHEN {$stockSql} <= ingredients.critical_stock THEN 'critical' WHEN {$stockSql} <= ingredients.minimum_stock THEN 'low' ELSE 'available' END AS stock_status", [$today, $today, $today])
            ->selectRaw("CASE WHEN ingredients.minimum_stock > ({$stockSql}) THEN ingredients.minimum_stock - ({$stockSql}) ELSE 0 END AS missing_to_minimum", [$today, $today])
            ->selectSub(InventoryBatch::query()->select('expires_at')->whereColumn('ingredient_id', 'ingredients.id')->where('available_quantity', '>', 0)->whereNotNull('expires_at')->whereDate('expires_at', '>=', $today)->orderBy('expires_at')->limit(1), 'next_expiry');
    }

    private function movementPayload(InventoryMovement $movement): array
    {
        $labels = [
            'purchase' => 'Compra', 'sale' => 'Venta', 'production_input' => 'Uso en producción',
            'production_output' => 'Producción terminada', 'adjustment' => 'Ajuste', 'waste' => 'Merma',
            'return' => 'Devolución por cancelación', 'reservation' => 'Reserva',
        ];
        $source = match ($movement->reference_type) {
            Purchase::class => 'Compra #'.$movement->reference_id,
            Order::class => 'Pedido #'.$movement->reference_id,
            ProductionBatch::class => 'Producción #'.$movement->reference_id,
            InventoryAdjustment::class => 'Ajuste #'.$movement->reference_id,
            default => null,
        };

        return $movement->toArray() + [
            'type_label' => $labels[$movement->type] ?? 'Movimiento de inventario',
            'origin_label' => $source,
        ];
    }
}
