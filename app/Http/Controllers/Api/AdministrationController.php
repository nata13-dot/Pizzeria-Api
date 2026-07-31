<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessProfile;
use App\Models\AuditLog;
use App\Models\CashDay;
use App\Models\DailyReport;
use App\Models\Order;
use App\Models\OrderDocument;
use App\Models\Setting;
use App\Services\CashReportService;
use App\Services\ReceiptService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AdministrationController extends Controller
{
    public function profile(Request $r)
    {
        return BusinessProfile::firstOrCreate(['branch_id' => $r->user()->branch_id], ['name' => 'Pizzería POS']);
    }

    public function updateProfile(Request $r)
    {
        $d = $r->validate(['name' => 'required|string', 'phone' => 'nullable|string', 'address' => 'nullable|string', 'primary_color' => 'nullable|string', 'tax_id' => 'nullable|string', 'receipt_footer' => 'nullable|string']);

        return BusinessProfile::updateOrCreate(['branch_id' => $r->user()->branch_id], $d);
    }

    public function settings(Request $r)
    {
        return Setting::where('branch_id', $r->user()->branch_id)->pluck('value', 'key');
    }

    public function updateSettings(Request $r)
    {
        $d = $r->validate(['settings' => 'required|array']);
        foreach ($d['settings'] as $k => $v) {
            Setting::updateOrCreate(['branch_id' => $r->user()->branch_id, 'key' => $k], ['value' => $v]);
        }

return $this->settings($r);
    }

    public function document(Request $r, Order $o, ReceiptService $s)
    {
        abort_unless($o->branch_id === $r->user()->branch_id, 404);
        $d = $r->validate(['type' => 'required|in:customer_html,customer_pdf,customer_image,kitchen,delivery']);
        $doc = $s->generate($o, $d['type'], $r->user());

        return response()->json($doc->toArray() + ['download_url' => $doc->path ? url('/api/order-documents/'.$doc->id.'/download') : null, 'whatsapp_url' => 'https://wa.me/?text='.rawurlencode("Orden #{$o->daily_number} - $".number_format((float) $o->total, 2))], 201);
    }

    public function download(Request $r, OrderDocument $d)
    {
        abort_unless($d->order_id && $d->path, 404);
        $order = Order::findOrFail($d->order_id);
        abort_unless($order->branch_id === $r->user()->branch_id, 404);

        return Storage::download($d->path);
    }

    public function openCash(Request $r)
    {
        $d = $r->validate(['date' => 'nullable|date', 'opening_amount' => 'required|numeric|min:0']);

        return response()->json(CashDay::firstOrCreate(['branch_id' => $r->user()->branch_id, 'date' => $d['date'] ?? today()], ['opened_by' => $r->user()->id, 'opening_amount' => $d['opening_amount']]), 201);
    }

    public function cashMovement(Request $r, CashDay $day)
    {
        abort_unless($day->branch_id === $r->user()->branch_id && ! $day->closed_at, 422);
        $d = $r->validate(['type' => 'required|in:income,expense', 'amount' => 'required|numeric|gt:0', 'category' => 'required|string', 'description' => 'nullable|string']);
        $d['user_id'] = $r->user()->id;

        return response()->json($day->movements()->create($d), 201);
    }

    public function closeCash(Request $r, CashDay $day, CashReportService $s)
    {
        abort_unless($day->branch_id === $r->user()->branch_id && ! $day->closed_at, 422);
        $d = $r->validate(['actual_amount' => 'required|numeric|min:0']);
        $summary = $s->summary($day->branch_id, $day->date->toDateString());
        $day->update(['closed_by' => $r->user()->id, 'expected_amount' => $summary['expected_cash'], 'actual_amount' => $d['actual_amount'], 'difference' => $d['actual_amount'] - $summary['expected_cash'], 'closed_at' => now()]);

        return $day->fresh();
    }

    public function cashReport(Request $r, CashReportService $s)
    {
        return $s->summary($r->user()->branch_id, $r->input('date', today()->toDateString()));
    }

    public function daily(Request $r, CashReportService $s)
    {
        $date = $r->input('date', today()->toDateString());
        $data = $s->summary($r->user()->branch_id, $date);
        $report = DailyReport::updateOrCreate(['branch_id' => $r->user()->branch_id, 'date' => $date], ['data' => $data]);
        $message = "Reporte diario {$date}\nVentas: $".number_format((float) $data['gross_sales'], 2)."\nPedidos: {$data['orders']}\nEfectivo esperado: $".number_format((float) $data['expected_cash'], 2)."\nCompras caja: $".number_format((float) $data['cash_purchases'], 2);

        return response()->json($report->toArray() + ['whatsapp_url' => 'https://wa.me/?text='.rawurlencode($message)]);
    }

    public function audits(Request $r)
    {
        return AuditLog::with('user')->when($r->entity, fn ($q, $entity) => $q->where('auditable_type', 'like', "%{$entity}%"))->latest()->paginate(50);
    }
}
