<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\BusinessProfile;
use App\Models\Ingredient;
use App\Models\IngredientType;
use App\Models\InventoryBatch;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $branch = Branch::firstOrCreate(['code' => 'MATRIZ'], ['name' => 'Sucursal matriz']);
        $roles = collect([
            ['name' => 'Administrador', 'slug' => 'administrador'],
            ['name' => 'Cajero', 'slug' => 'cajero'],
            ['name' => 'Cocina', 'slug' => 'cocina'],
            ['name' => 'Repartidor', 'slug' => 'repartidor'],
        ])->mapWithKeys(fn (array $role) => [$role['slug'] => Role::firstOrCreate(['slug' => $role['slug']], $role)]);

        $permissionMap = [
            'pos.use' => ['cajero'], 'orders.view' => ['cajero', 'cocina', 'repartidor'],
            'kitchen.use' => ['cocina'], 'delivery.use' => ['repartidor'],
            'inventory.view' => ['cajero'], 'purchases.manage' => [],
            'production.manage' => [], 'customers.manage' => ['cajero'],
            'cash.manage' => ['cajero'], 'documents.generate' => ['cajero', 'cocina', 'repartidor'],
            'stock.override' => [], 'orders.cancel_advanced' => [],
        ];
        $permissionNames = [
            'pos.use' => 'Usar caja / punto de venta',
            'orders.view' => 'Ver pedidos',
            'kitchen.use' => 'Usar pantalla de cocina',
            'delivery.use' => 'Gestionar reparto',
            'inventory.view' => 'Ver inventario',
            'purchases.manage' => 'Gestionar compras',
            'production.manage' => 'Gestionar producción',
            'customers.manage' => 'Gestionar clientes',
            'cash.manage' => 'Gestionar cortes de caja',
            'documents.generate' => 'Generar tickets y documentos',
            'stock.override' => 'Autorizar faltantes de inventario',
            'orders.cancel_advanced' => 'Cancelar pedidos avanzados',
        ];
        $permissions = collect($permissionMap)->mapWithKeys(
            fn (array $roleSlugs, string $slug) => [
                $slug => Permission::updateOrCreate(['slug' => $slug], ['name' => $permissionNames[$slug]]),
            ],
        );
        foreach ($roles as $roleSlug => $role) {
            $role->permissions()->sync(
                $roleSlug === 'administrador'
                    ? $permissions->pluck('id')
                    : $permissions
                        ->filter(fn (Permission $permission, string $slug) => in_array($roleSlug, $permissionMap[$slug], true))
                        ->pluck('id'),
            );
        }

        $seedPassword = env('PIZZERIA_SEED_PASSWORD');
        if (! $seedPassword && app()->environment(['local', 'testing'])) {
            $seedPassword = 'Pizzeria123!';
        }
        if (! $seedPassword) {
            throw new \RuntimeException('Configura PIZZERIA_SEED_PASSWORD antes de crear el usuario administrador.');
        }

        User::updateOrCreate(['email' => 'admin@pizzeria.local'], [
            'name' => 'Administrador',
            'username' => 'admin',
            'password' => $seedPassword,
            'branch_id' => $branch->id,
            'role_id' => $roles['administrador']->id,
            'active' => true,
        ]);

        $demoUsers = app()->environment(['local', 'testing']) ? [
            ['name' => 'Cajero Demo', 'username' => 'cajero', 'email' => 'cajero@pizzeria.local', 'role' => 'cajero'],
            ['name' => 'Cocina Demo', 'username' => 'cocina', 'email' => 'cocina@pizzeria.local', 'role' => 'cocina'],
            ['name' => 'Repartidor Demo', 'username' => 'repartidor', 'email' => 'repartidor@pizzeria.local', 'role' => 'repartidor'],
        ] : [];
        foreach ($demoUsers as $user) {
            User::updateOrCreate(['email' => $user['email']], [
                'name' => $user['name'],
                'username' => $user['username'],
                'password' => $seedPassword,
                'branch_id' => $branch->id,
                'role_id' => $roles[$user['role']]->id,
                'active' => true,
            ]);
        }

        foreach ([
            ['name' => 'Gramos', 'symbol' => 'g', 'dimension' => 'mass', 'base_factor' => 1],
            ['name' => 'Kilogramos', 'symbol' => 'kg', 'dimension' => 'mass', 'base_factor' => 1000],
            ['name' => 'Mililitros', 'symbol' => 'ml', 'dimension' => 'volume', 'base_factor' => 1],
            ['name' => 'Litros', 'symbol' => 'l', 'dimension' => 'volume', 'base_factor' => 1000],
            ['name' => 'Piezas', 'symbol' => 'pz', 'dimension' => 'count', 'base_factor' => 1],
            ['name' => 'Paquetes', 'symbol' => 'paq', 'dimension' => 'count', 'base_factor' => 1],
            ['name' => 'Cajas', 'symbol' => 'caja', 'dimension' => 'count', 'base_factor' => 1],
            ['name' => 'Bolsas', 'symbol' => 'bolsa', 'dimension' => 'count', 'base_factor' => 1],
            ['name' => 'Porciones', 'symbol' => 'porción', 'dimension' => 'count', 'base_factor' => 1],
        ] as $unit) {
            Unit::updateOrCreate(['symbol' => $unit['symbol']], $unit);
        }

        foreach (['Lácteo', 'Embutido', 'Salsa', 'Harina', 'Masa', 'Vegetal', 'Carne', 'Desechable', 'Bebida', 'Otro'] as $type) {
            IngredientType::firstOrCreate(['name' => $type], ['expiry_alert_days' => 3, 'active' => true]);
        }

        if (app()->environment('testing')) {
            return;
        }

        BusinessProfile::updateOrCreate(['branch_id' => $branch->id], [
            'name' => 'Pizzeria Demo',
            'phone' => '555-0101',
            'address' => 'Sucursal matriz',
            'primary_color' => '#cf4b32',
            'receipt_footer' => 'Gracias por su compra.',
        ]);

        $g = Unit::where('symbol', 'g')->first();
        $pz = Unit::where('symbol', 'pz')->first();
        $lacteo = IngredientType::where('name', 'Lácteo')->first();
        $embutido = IngredientType::where('name', 'Embutido')->first();
        $salsa = IngredientType::where('name', 'Salsa')->first();
        $desechable = IngredientType::where('name', 'Desechable')->first();
        $masa = IngredientType::where('name', 'Masa')->first();

        $ingredients = [
            'Masa grande' => ['unit' => $pz, 'type' => $masa, 'stock' => 30, 'cost' => 12],
            'Queso mozzarella' => ['unit' => $g, 'type' => $lacteo, 'stock' => 10000, 'cost' => 0.12],
            'Salsa de tomate' => ['unit' => $g, 'type' => $salsa, 'stock' => 6000, 'cost' => 0.05],
            'Pepperoni' => ['unit' => $g, 'type' => $embutido, 'stock' => 5000, 'cost' => 0.18],
            'Caja pizza grande' => ['unit' => $pz, 'type' => $desechable, 'stock' => 80, 'cost' => 5],
        ];

        $createdIngredients = collect($ingredients)->mapWithKeys(function (array $row, string $name) use ($branch) {
            $ingredient = Ingredient::updateOrCreate(['branch_id' => $branch->id, 'name' => $name], [
                'ingredient_type_id' => $row['type']?->id,
                'base_unit_id' => $row['unit']->id,
                'minimum_stock' => $row['unit']->symbol === 'g' ? 1000 : 5,
                'critical_stock' => $row['unit']->symbol === 'g' ? 500 : 2,
                'expiry_alert_days' => 3,
                'active' => true,
            ]);
            InventoryBatch::updateOrCreate(['branch_id' => $branch->id, 'ingredient_id' => $ingredient->id, 'lot_code' => 'DEMO-001'], [
                'received_at' => today(),
                'expires_at' => today()->addDays(14),
                'initial_quantity' => $row['stock'],
                'available_quantity' => $row['stock'],
                'unit_cost' => $row['cost'],
            ]);

            return [$name => $ingredient];
        });

        $category = ProductCategory::updateOrCreate(['branch_id' => $branch->id, 'name' => 'Pizzas'], [
            'sort_order' => 1,
            'active' => true,
        ]);
        $product = Product::updateOrCreate(['branch_id' => $branch->id, 'name' => 'Pizza Pepperoni'], [
            'product_category_id' => $category->id,
            'type' => 'pizza',
            'description' => 'Pizza grande de pepperoni para pruebas.',
            'active' => true,
        ]);
        $variant = ProductVariant::updateOrCreate(['product_id' => $product->id, 'name' => 'Grande'], [
            'sku' => 'PIZ-PEP-GDE',
            'price' => 189,
            'max_flavors' => 1,
            'allows_half_and_half' => true,
            'active' => true,
        ]);
        $flavor = $product->flavors()->updateOrCreate(['name' => 'Pepperoni'], [
            'ingredient_id' => $createdIngredients['Pepperoni']->id,
            'active' => true,
        ]);
        $recipe = Recipe::updateOrCreate(['product_variant_id' => $variant->id, 'product_flavor_id' => $flavor->id], [
            'name' => 'Pizza grande pepperoni',
            'active' => true,
        ]);
        foreach ([
            ['ingredient' => 'Masa grande', 'quantity' => 1, 'component' => 'base'],
            ['ingredient' => 'Queso mozzarella', 'quantity' => 250, 'component' => 'base'],
            ['ingredient' => 'Salsa de tomate', 'quantity' => 120, 'component' => 'sauce'],
            ['ingredient' => 'Pepperoni', 'quantity' => 90, 'component' => 'topping'],
            ['ingredient' => 'Caja pizza grande', 'quantity' => 1, 'component' => 'packaging'],
        ] as $item) {
            $recipe->items()->updateOrCreate([
                'ingredient_id' => $createdIngredients[$item['ingredient']]->id,
                'component' => $item['component'],
            ], ['quantity' => $item['quantity']]);
        }
    }
}
