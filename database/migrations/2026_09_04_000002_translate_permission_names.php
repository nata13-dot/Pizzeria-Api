<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const NAMES = [
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

    public function up(): void
    {
        foreach (self::NAMES as $slug => $name) {
            DB::table('permissions')->where('slug', $slug)->update(['name' => $name]);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::NAMES) as $slug) {
            DB::table('permissions')->where('slug', $slug)->update(['name' => $slug]);
        }
    }
};
