<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\Ingredient;
use App\Models\InventoryAdjustment;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    private const REMOVAL_REASONS = ['waste', 'expiry', 'preparation_error', 'gift', 'internal_use', 'loss'];

    public function batches(Request $r)
    {
        return InventoryBatch::with('ingredient')->where('branch_id', $r->user()->branch_id)->orderByRaw('expires_at IS NULL')->orderBy('expires_at')->paginate(30);
    }

    public function movements(Request $r)
    {
        return InventoryMovement::with(['ingredient', 'batch'])->where('branch_id', $r->user()->branch_id)->latest()->paginate(30);
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
}
