<?php

namespace App\Console\Commands;

use App\Models\Alert;
use App\Models\Branch;
use App\Models\DailyReport;
use App\Models\Ingredient;
use App\Models\User;
use App\Notifications\SystemAlertNotification;
use App\Services\CashReportService;
use App\Services\InventoryService;
use App\Services\LoyaltyService;
use App\Services\OrderService;
use App\Services\PushService;
use Illuminate\Console\Command;
use Throwable;

class RunDailyOperations extends Command
{
    protected $signature = 'pizzeria:daily-operations {--report}';

    protected $description = 'Actualiza alertas, vencimientos, pedidos pendientes y reporte diario';

    public function handle(): int
    {
        app(OrderService::class)->expirePending();
        app(LoyaltyService::class)->expire();
        Ingredient::each(fn ($i) => app(InventoryService::class)->refreshAlerts($i));
        Alert::whereNull('resolved_at')->where('severity', 'critical')->where('created_at', '>=', now()->subMinutes(6))->get()->groupBy('branch_id')->each(function ($alerts, $branch) {
            User::where('branch_id', $branch)->whereHas('role', fn ($q) => $q->where('slug', 'administrador'))->each(function ($u) use ($alerts) {
                $message = $alerts->pluck('message')->join(' · ');
                $u->notify(new SystemAlertNotification('Alerta crítica de inventario', $message));
                try {
                    app(PushService::class)->send($u, 'Alerta crítica', $message, ['type' => 'inventory']);
                } catch (Throwable $e) {
                    report($e);
                }
            });
        });
        if ($this->option('report')) {
            foreach (Branch::where('active', true)->get() as $b) {
                $data = app(CashReportService::class)->summary($b->id, today()->toDateString());
                DailyReport::updateOrCreate(['branch_id' => $b->id, 'date' => today()], ['data' => $data]);
                User::where('branch_id', $b->id)->whereHas('role', fn ($q) => $q->where('slug', 'administrador'))->each(fn ($u) => $u->notify(new SystemAlertNotification('Reporte diario disponible', 'El reporte diario ya está listo.', ['date' => today()->toDateString()])));
            }
        }

return self::SUCCESS;
    }
}
