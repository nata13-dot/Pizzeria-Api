<?php

use App\Models\Ingredient;
use App\Models\InventoryAdjustment;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Recipe;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->foreignId('branch_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        foreach ([
            User::class => 'users',
            Ingredient::class => 'ingredients',
            Purchase::class => 'purchases',
            Product::class => 'products',
            Order::class => 'orders',
            InventoryAdjustment::class => 'inventory_adjustments',
            Setting::class => 'settings',
        ] as $type => $table) {
            $this->backfillDirectBranch($type, $table);
        }
        $this->backfillRecipeBranch();
        $this->backfillLoyaltyBranch();
        $this->backfillActorBranch();

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->index(['branch_id', 'created_at'], 'audit_logs_branch_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex('audit_logs_branch_created_index');
        });
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('branch_id');
        });
    }

    private function backfillDirectBranch(string $type, string $table): void
    {
        DB::table('audit_logs')
            ->whereNull('branch_id')
            ->where('auditable_type', $type)
            ->whereNotNull('auditable_id')
            ->orderBy('id')
            ->chunkById(500, function ($logs) use ($table): void {
                $branches = DB::table($table)
                    ->whereIn('id', $logs->pluck('auditable_id'))
                    ->whereNotNull('branch_id')
                    ->pluck('branch_id', 'id');
                $this->applyBranches($logs, $branches, 'auditable_id');
            });
    }

    private function backfillRecipeBranch(): void
    {
        DB::table('audit_logs')
            ->whereNull('branch_id')
            ->where('auditable_type', Recipe::class)
            ->whereNotNull('auditable_id')
            ->orderBy('id')
            ->chunkById(500, function ($logs): void {
                $branches = DB::table('recipes')
                    ->join('product_variants', 'product_variants.id', '=', 'recipes.product_variant_id')
                    ->join('products', 'products.id', '=', 'product_variants.product_id')
                    ->whereIn('recipes.id', $logs->pluck('auditable_id'))
                    ->pluck('products.branch_id', 'recipes.id');
                $this->applyBranches($logs, $branches, 'auditable_id');
            });
    }

    private function backfillLoyaltyBranch(): void
    {
        DB::table('audit_logs')
            ->whereNull('branch_id')
            ->where('auditable_type', LoyaltyTransaction::class)
            ->whereNotNull('auditable_id')
            ->orderBy('id')
            ->chunkById(500, function ($logs): void {
                $branches = DB::table('loyalty_transactions')
                    ->join('customers', 'customers.id', '=', 'loyalty_transactions.customer_id')
                    ->whereIn('loyalty_transactions.id', $logs->pluck('auditable_id'))
                    ->pluck('customers.branch_id', 'loyalty_transactions.id');
                $this->applyBranches($logs, $branches, 'auditable_id');
            });
    }

    private function backfillActorBranch(): void
    {
        DB::table('audit_logs')
            ->whereNull('branch_id')
            ->whereNotNull('user_id')
            ->orderBy('id')
            ->chunkById(500, function ($logs): void {
                $branches = DB::table('users')
                    ->whereIn('id', $logs->pluck('user_id'))
                    ->whereNotNull('branch_id')
                    ->pluck('branch_id', 'id');
                $this->applyBranches($logs, $branches, 'user_id');
            });
    }

    private function applyBranches($logs, $branches, string $sourceKey): void
    {
        foreach ($logs as $log) {
            $branchId = $branches->get($log->{$sourceKey});
            if ($branchId !== null) {
                DB::table('audit_logs')->where('id', $log->id)->update(['branch_id' => $branchId]);
            }
        }
    }
};
