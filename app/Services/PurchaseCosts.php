<?php

namespace App\Services;

use App\Models\PurchaseItem;

final class PurchaseCosts
{
    /**
     * Price paid per presentation in the most recent purchase of each one.
     *
     * @param  array<int>  $presentationIds
     * @return array<int, float>
     */
    public function lastUnitCosts(int $branchId, array $presentationIds): array
    {
        if (! $presentationIds) {
            return [];
        }

        return PurchaseItem::query()
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->where('purchases.branch_id', $branchId)
            ->whereIn('purchase_items.ingredient_presentation_id', $presentationIds)
            ->where('purchase_items.presentations_quantity', '>', 0)
            ->orderByDesc('purchases.purchased_at')->orderByDesc('purchase_items.id')
            ->get(['purchase_items.ingredient_presentation_id', 'purchase_items.total_cost', 'purchase_items.presentations_quantity'])
            ->unique('ingredient_presentation_id')
            ->mapWithKeys(fn ($item) => [(int) $item->ingredient_presentation_id => round((float) $item->total_cost / (float) $item->presentations_quantity, 2)])
            ->all();
    }
}
