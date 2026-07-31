<?php
namespace App\Services;

use App\Models\Alert;
use App\Models\Ingredient;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function move(InventoryBatch $batch, float $quantity, string $type, ?User $user, mixed $reference = null, ?string $reason = null, ?string $comment = null): InventoryMovement
    {
        $before=(float)$batch->available_quantity; $after=$before+$quantity;
        if ($after < 0) throw ValidationException::withMessages(['quantity'=>'El lote no tiene existencia suficiente.']);
        $batch->update(['available_quantity'=>$after]);
        return InventoryMovement::create(['branch_id'=>$batch->branch_id,'ingredient_id'=>$batch->ingredient_id,'inventory_batch_id'=>$batch->id,'user_id'=>$user?->id,
            'type'=>$type,'quantity'=>$quantity,'quantity_before'=>$before,'quantity_after'=>$after,'reference_type'=>$reference ? $reference::class : null,
            'reference_id'=>$reference?->id,'reason'=>$reason,'comment'=>$comment]);
    }

    /** Descuenta por FEFO. Si no alcanza, informa el faltante sin alterar inventario. */
    public function consumeFefo(Ingredient $ingredient, float $quantity, string $type, ?User $user, mixed $reference = null): array
    {
        return DB::transaction(function () use ($ingredient,$quantity,$type,$user,$reference): array {
            $batches=$ingredient->batches()->where('available_quantity','>',0)->orderByRaw('expires_at IS NULL')->orderBy('expires_at')->orderBy('received_at')->lockForUpdate()->get();
            $available=(float)$batches->sum('available_quantity');
            if ($available < $quantity) return ['consumed'=>0,'shortage'=>$quantity-$available,'movements'=>[]];
            $remaining=$quantity; $movements=[];
            foreach($batches as $batch){ if($remaining<=0)break; $take=min($remaining,(float)$batch->available_quantity); $movements[]=$this->move($batch,-$take,$type,$user,$reference); $remaining-=$take; }
            $this->refreshAlerts($ingredient); return ['consumed'=>$quantity,'shortage'=>0,'movements'=>$movements];
        });
    }

    public function refreshAlerts(Ingredient $ingredient): void
    {
        $stock=(float)$ingredient->batches()->sum('available_quantity');
        Alert::where('ingredient_id',$ingredient->id)->whereIn('type',['low_stock','critical_stock'])->whereNull('resolved_at')->update(['resolved_at'=>now()]);
        if($stock <= (float)$ingredient->critical_stock) Alert::create(['branch_id'=>$ingredient->branch_id,'ingredient_id'=>$ingredient->id,'type'=>'critical_stock','severity'=>'critical','message'=>"{$ingredient->name}: stock crítico ({$stock})."]);
        elseif($stock <= (float)$ingredient->minimum_stock) Alert::create(['branch_id'=>$ingredient->branch_id,'ingredient_id'=>$ingredient->id,'type'=>'low_stock','severity'=>'warning','message'=>"{$ingredient->name}: stock bajo ({$stock})."]);
        foreach($ingredient->batches()->where('available_quantity','>',0)->whereNotNull('expires_at')->get() as $batch){
            $type=$batch->expires_at->isPast()?'expired':($batch->expires_at->lte(today()->addDays($ingredient->expiry_alert_days))?'expiring':null); if(!$type)continue;
            Alert::firstOrCreate(['inventory_batch_id'=>$batch->id,'type'=>$type,'resolved_at'=>null],['branch_id'=>$ingredient->branch_id,'ingredient_id'=>$ingredient->id,'severity'=>$type==='expired'?'critical':'warning','message'=>"{$ingredient->name}, lote {$batch->lot_code}: ".($type==='expired'?'caducado':'próximo a caducar').'.']);
        }
    }
}
