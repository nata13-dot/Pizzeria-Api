<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltyRule;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\OrderItemComponent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoyaltyService
{
    public function __construct(private readonly BranchSettings $settings) {}

    public function award(Order $order): void
    {
        if (! $order->customer_id || $order->status !== 'delivered' || ! (bool) $this->settings->get($order->branch_id, 'loyalty_enabled')) {
            return;
        }

        DB::transaction(function () use ($order): void {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $customer = Customer::query()->whereKey($order->customer_id)->lockForUpdate()->firstOrFail();
            if ($customer->branch_id !== $order->branch_id || $order->status !== 'delivered') {
                return;
            }

            foreach (LoyaltyRule::query()->where('branch_id', $order->branch_id)->where('active', true)->get() as $rule) {
                if ($order->courtesy && ! $rule->courtesy_eligible) {
                    continue;
                }
                $points = match ($rule->type) {
                    'per_amount' => floor((float) $order->total / (float) $rule->threshold) * (float) $rule->points,
                    'per_order' => (float) $rule->points,
                    'product' => $this->matching($order, 'product_id', $rule) * (float) $rule->points,
                    'category' => $this->matching($order, 'product_category_id', $rule) * (float) $rule->points,
                    'promotion' => $this->promotionApplies($order, $rule) ? (float) $rule->points : 0,
                    'birthday' => $this->isCustomerBirthday($customer, $order) ? (float) $rule->points : 0,
                    default => 0,
                };
                if ($points <= 0) {
                    continue;
                }

                if (LoyaltyTransaction::query()
                    ->where('customer_id', $customer->id)
                    ->where('loyalty_rule_id', $rule->id)
                    ->where('order_id', $order->id)
                    ->where('type', 'earned')
                    ->exists()) {
                    continue;
                }

                $balanceBefore = (float) $customer->loyaltyTransactions()->sum('points');
                $remainingPoints = max(0, $points + min(0, $balanceBefore));

                LoyaltyTransaction::firstOrCreate([
                    'customer_id' => $customer->id,
                    'loyalty_rule_id' => $rule->id,
                    'order_id' => $order->id,
                    'type' => 'earned',
                ], [
                    'points' => $points,
                    'remaining_points' => $remainingPoints,
                    'expires_at' => $rule->expires_days ? now()->addDays($rule->expires_days) : null,
                ]);
            }
        });
    }

    public function reverseForCancellation(Order $order, $user, string $comment): void
    {
        if (! $order->customer_id) {
            return;
        }

        DB::transaction(function () use ($order, $user, $comment): void {
            $customer = Customer::query()->whereKey($order->customer_id)->lockForUpdate()->firstOrFail();
            if ($customer->branch_id !== $order->branch_id) {
                return;
            }
            $this->expireCustomerLocked($customer);

            foreach ($order->loyaltyRedemptions()->whereNull('cancelled_at')->lockForUpdate()->get() as $redemption) {
                $redemption->update([
                    'cancelled_at' => now(),
                    'cancelled_by' => $user->id,
                    'cancellation_comment' => $comment,
                ]);
                LoyaltyTransaction::create([
                    'customer_id' => $customer->id,
                    'order_id' => $order->id,
                    'user_id' => $user->id,
                    'type' => 'adjustment',
                    'points' => (float) $redemption->points,
                    'remaining_points' => (float) $redemption->points,
                    'comment' => 'Restitución del canje #'.$redemption->id.' por cancelación: '.$comment,
                ]);
            }

            $earned = $customer->loyaltyTransactions()
                ->where('order_id', $order->id)
                ->where('type', 'earned')
                ->whereDoesntHave('reversal')
                ->lockForUpdate()
                ->get();
            foreach ($earned as $transaction) {
                $points = (float) $transaction->points;
                $left = $points;
                $available = $customer->loyaltyTransactions()
                    ->where('remaining_points', '>', 0)
                    ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->orderByRaw('expires_at IS NULL')
                    ->orderBy('expires_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                foreach ($available as $source) {
                    $take = min($left, (float) $source->remaining_points);
                    $source->decrement('remaining_points', $take);
                    $left -= $take;
                    if ($left <= .00001) {
                        break;
                    }
                }

                LoyaltyTransaction::create([
                    'customer_id' => $customer->id,
                    'loyalty_rule_id' => $transaction->loyalty_rule_id,
                    'order_id' => $order->id,
                    'user_id' => $user->id,
                    'type' => 'adjustment',
                    'points' => -$points,
                    'remaining_points' => 0,
                    'reversal_of_transaction_id' => $transaction->id,
                    'comment' => 'Reversión de puntos por cancelación: '.$comment,
                ]);
            }
        });
    }

    public function redeem(
        Customer $customer,
        float $points,
        $user,
        ?Order $order = null,
        ?float $ignoredClientValue = null,
    ): LoyaltyRedemption {
        return DB::transaction(function () use ($customer, $points, $user, $order): LoyaltyRedemption {
            $customer = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            if (! (bool) $this->settings->get($customer->branch_id, 'loyalty_enabled')) {
                throw ValidationException::withMessages(['points' => 'El programa de puntos está desactivado.']);
            }
            if (! $order) {
                throw ValidationException::withMessages(['order_id' => 'Debes vincular el canje a una orden.']);
            }
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->branch_id !== $customer->branch_id
                || $order->customer_id !== $customer->id
                || ! in_array($order->status, ['draft', 'pending_payment'], true)) {
                throw ValidationException::withMessages([
                    'order_id' => 'La orden no pertenece al cliente o ya no admite canjes.',
                ]);
            }

            $this->expireCustomerLocked($customer);
            $transactions = $customer->loyaltyTransactions()
                ->where('remaining_points', '>', 0)
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->orderByRaw('expires_at IS NULL')
                ->orderBy('expires_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ((float) $transactions->sum('remaining_points') + .00001 < $points) {
                throw ValidationException::withMessages(['points' => 'Saldo de puntos insuficiente.']);
            }

            $pointValue = max(0, (float) $this->settings->get($customer->branch_id, 'loyalty_point_value'));
            $value = round($points * $pointValue, 2);
            if ($value <= 0 || $value > (float) $order->total + .009) {
                throw ValidationException::withMessages([
                    'points' => 'El canje excede el total de la orden o no tiene valor configurado.',
                ]);
            }

            $left = $points;
            foreach ($transactions as $transaction) {
                $take = min($left, (float) $transaction->remaining_points);
                $transaction->decrement('remaining_points', $take);
                $left -= $take;
                if ($left <= .00001) {
                    break;
                }
            }
            if ($left > .00001) {
                throw ValidationException::withMessages(['points' => 'No fue posible completar el canje.']);
            }

            $redemption = LoyaltyRedemption::create([
                'customer_id' => $customer->id,
                'order_id' => $order->id,
                'user_id' => $user->id,
                'points' => $points,
                'value' => $value,
            ]);
            LoyaltyTransaction::create([
                'customer_id' => $customer->id,
                'order_id' => $order->id,
                'user_id' => $user->id,
                'type' => 'redeemed',
                'points' => -$points,
                'remaining_points' => 0,
                'comment' => 'Canje #'.$redemption->id,
            ]);
            $discount = (float) $order->discount + $value;
            $order->update([
                'discount' => $discount,
                'total' => max(0, (float) $order->subtotal - $discount + (float) $order->delivery_fee),
            ]);

            return $redemption;
        });
    }

    public function adjust(
        Customer $customer,
        float $points,
        $user,
        string $comment,
        ?string $expiresAt = null,
    ): LoyaltyTransaction {
        return DB::transaction(function () use ($customer, $points, $user, $comment, $expiresAt): LoyaltyTransaction {
            $customer = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            if ($customer->branch_id !== $user->branch_id) {
                abort(404);
            }
            $this->expireCustomerLocked($customer);

            if ($points < 0) {
                $left = abs($points);
                $transactions = $customer->loyaltyTransactions()
                    ->where('remaining_points', '>', 0)
                    ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->orderByRaw('expires_at IS NULL')
                    ->orderBy('expires_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ((float) $transactions->sum('remaining_points') + .00001 < $left) {
                    throw ValidationException::withMessages(['points' => 'El ajuste excede el saldo disponible.']);
                }

                foreach ($transactions as $transaction) {
                    $take = min($left, (float) $transaction->remaining_points);
                    $transaction->decrement('remaining_points', $take);
                    $left -= $take;
                    if ($left <= .00001) {
                        break;
                    }
                }
            }

            return LoyaltyTransaction::create([
                'customer_id' => $customer->id,
                'user_id' => $user->id,
                'type' => 'adjustment',
                'points' => $points,
                'remaining_points' => $points > 0 ? $points : 0,
                'expires_at' => $points > 0 && $expiresAt ? CarbonImmutable::parse($expiresAt) : null,
                'comment' => $comment,
            ]);
        });
    }

    public function expire(?Customer $customer = null): int
    {
        $query = LoyaltyTransaction::query()
            ->where('remaining_points', '>', 0)
            ->where('expires_at', '<=', now());
        if ($customer) {
            $query->where('customer_id', $customer->id);
        }

        $expired = 0;
        foreach ($query->pluck('id') as $id) {
            DB::transaction(function () use ($id, &$expired): void {
                $transaction = LoyaltyTransaction::query()->whereKey($id)->lockForUpdate()->first();
                if (! $transaction || (float) $transaction->remaining_points <= 0 || $transaction->expires_at?->isFuture()) {
                    return;
                }
                Customer::query()->whereKey($transaction->customer_id)->lockForUpdate()->first();
                $points = (float) $transaction->remaining_points;
                $transaction->update(['remaining_points' => 0]);
                LoyaltyTransaction::create([
                    'customer_id' => $transaction->customer_id,
                    'loyalty_rule_id' => $transaction->loyalty_rule_id,
                    'type' => 'expired',
                    'points' => -$points,
                    'remaining_points' => 0,
                ]);
                $expired++;
            });
        }

        return $expired;
    }

    private function expireCustomerLocked(Customer $customer): void
    {
        foreach ($customer->loyaltyTransactions()
            ->where('remaining_points', '>', 0)
            ->where('expires_at', '<=', now())
            ->lockForUpdate()
            ->get() as $transaction) {
            $points = (float) $transaction->remaining_points;
            $transaction->update(['remaining_points' => 0]);
            LoyaltyTransaction::create([
                'customer_id' => $customer->id,
                'loyalty_rule_id' => $transaction->loyalty_rule_id,
                'type' => 'expired',
                'points' => -$points,
                'remaining_points' => 0,
            ]);
        }
    }

    private function matching(Order $order, string $field, LoyaltyRule $rule): float
    {
        $ids = $rule->conditions['ids'] ?? [];
        if (! $ids) {
            return 0.0;
        }
        $direct = (float) $order->items()
            ->whereHas('variant.product', fn ($query) => $query->whereIn($field, $ids))
            ->sum('quantity');
        $components = (float) OrderItemComponent::query()
            ->whereHas('orderItem', fn ($query) => $query->where('order_id', $order->id))
            ->whereHas('variant.product', fn ($query) => $query->whereIn($field, $ids))
            ->sum('quantity');

        return $direct + $components;
    }

    private function promotionApplies(Order $order, LoyaltyRule $rule): bool
    {
        $date = $order->order_date?->toDateString();
        $startsAt = $rule->conditions['starts_at'] ?? null;
        $endsAt = $rule->conditions['ends_at'] ?? null;

        return (! $startsAt || $date >= CarbonImmutable::parse($startsAt)->toDateString())
            && (! $endsAt || $date <= CarbonImmutable::parse($endsAt)->toDateString());
    }

    private function isCustomerBirthday(Customer $customer, Order $order): bool
    {
        return $customer->birth_date
            && $order->order_date
            && $customer->birth_date->format('m-d') === $order->order_date->format('m-d');
    }
}
