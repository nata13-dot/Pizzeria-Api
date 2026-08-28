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
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class RunDailyOperations extends Command
{
    protected $signature = 'pizzeria:daily-operations {--report}';

    protected $description = 'Actualiza alertas, vencimientos, pedidos pendientes y reporte diario';

    public function handle(): int
    {
        $orders = app(OrderService::class);
        $orders->expirePending();
        $orders->dispatchScheduled();
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
        $this->generateReports(false);
        if ($this->option('report')) {
            $this->generateReports(true);
        }

        return self::SUCCESS;
    }

    private function generateReports(bool $currentDay): void
    {
        foreach (Branch::where('active', true)->get() as $branch) {
            $localToday = CarbonImmutable::now($branch->timezone ?: config('app.timezone'))->startOfDay();
            $date = ($currentDay ? $localToday : $localToday->subDay())->toDateString();
            $data = app(CashReportService::class)->summary($branch->id, $date);
            $report = DailyReport::firstOrCreate(
                ['branch_id' => $branch->id, 'date' => $date],
                ['data' => $data],
            );
            if ($currentDay && ! $report->wasRecentlyCreated) {
                $report->update(['data' => $data]);
            }
            if (! $report->wasRecentlyCreated) {
                continue;
            }

            User::where('branch_id', $branch->id)
                ->whereHas('role', fn ($query) => $query->where('slug', 'administrador'))
                ->each(fn ($user) => $user->notify(new SystemAlertNotification(
                    'Reporte diario disponible',
                    "El reporte del {$date} ya está listo.",
                    ['date' => $date],
                )));
        }
    }
}
