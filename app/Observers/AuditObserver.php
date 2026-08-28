<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Model;

class AuditObserver
{
    public function created(Model $model): void
    {
        $this->write('created', $model, [], $model->getAttributes());
    }

    public function updated(Model $model): void
    {
        $new = $model->getChanges();
        $old = collect($model->getOriginal())->only(array_keys($new))->all();
        $this->write('updated', $model, $old, $new);
    }

    public function deleted(Model $model): void
    {
        $this->write('deleted', $model, $model->getOriginal(), []);
    }

    private function write(string $action, Model $model, array $old, array $new): void
    {
        foreach (['password', 'remember_token'] as $key) {
            unset($old[$key], $new[$key]);
        }
        AuditLog::create([
            'branch_id' => $this->branchId($model),
            'user_id' => auth()->id(),
            'action' => $action,
            'auditable_type' => $model::class,
            'auditable_id' => $model->getKey(),
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }

    private function branchId(Model $model): ?int
    {
        $branchId = $model->getAttribute('branch_id');
        if ($branchId !== null) {
            return (int) $branchId;
        }

        if ($model instanceof Recipe) {
            $branchId = $model->variant()->with('product')->first()?->product?->branch_id;
        } elseif ($model instanceof LoyaltyTransaction) {
            $branchId = Customer::whereKey($model->customer_id)->value('branch_id')
                ?? Order::whereKey($model->order_id)->value('branch_id');
        }

        return $branchId !== null ? (int) $branchId : auth()->user()?->branch_id;
    }
}
