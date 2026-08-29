<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BusinessProfile;
use App\Models\CashDay;
use App\Models\DailyReport;
use App\Models\Order;
use App\Models\OrderDocument;
use App\Models\Setting;
use App\Services\BranchClock;
use App\Services\BranchSettings;
use App\Services\CashReportService;
use App\Services\ReceiptService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdministrationController extends Controller
{
    public function profile(Request $r)
    {
        return BusinessProfile::firstOrCreate(
            ['branch_id' => $r->user()->branch_id],
            ['name' => 'Pizzería POS'],
        )->fresh();
    }

    public function updateProfile(Request $r)
    {
        $data = $r->validate([
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'primary_color' => ['sometimes', 'nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['sometimes', 'nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:50'],
            'social_links' => ['sometimes', 'nullable', 'array', 'max:10'],
            'social_links.*' => ['array:name,value'],
            'social_links.*.name' => ['required', 'string', 'max:50'],
            'social_links.*.value' => ['required', 'string', 'max:255'],
            'show_business_details' => ['sometimes', 'boolean'],
            'receipt_footer' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'logo_base64' => ['sometimes', 'nullable', 'string', 'max:1500000'],
            'remove_logo' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('primary_color', $data) && $data['primary_color'] === null) {
            $data['primary_color'] = '#cf4b32';
        }
        if (array_key_exists('secondary_color', $data) && $data['secondary_color'] === null) {
            $data['secondary_color'] = '#29231f';
        }

        $socialNames = collect($data['social_links'] ?? [])
            ->pluck('name')
            ->map(fn ($name) => mb_strtolower(trim((string) $name)));
        if ($socialNames->unique()->count() !== $socialNames->count()) {
            throw ValidationException::withMessages([
                'social_links' => 'No repitas la misma red social.',
            ]);
        }
        if ($r->filled('logo_base64') && ($data['remove_logo'] ?? false)) {
            throw ValidationException::withMessages([
                'remove_logo' => 'No puedes cargar y eliminar el logo en la misma solicitud.',
            ]);
        }

        $profile = BusinessProfile::firstOrNew(['branch_id' => $r->user()->branch_id]);
        $oldLogoPath = $profile->logo_path;
        $newLogoPath = null;
        if ($r->filled('logo_base64')) {
            [$contents, $extension] = $this->decodeLogo($data['logo_base64']);
            $newLogoPath = 'business-logos/branch-'.$r->user()->branch_id.'/'.Str::uuid().'.'.$extension;
            if (! Storage::disk('local')->put($newLogoPath, $contents)) {
                throw ValidationException::withMessages(['logo_base64' => 'No se pudo almacenar el logo.']);
            }
            $data['logo_path'] = $newLogoPath;
        } elseif ($data['remove_logo'] ?? false) {
            $data['logo_path'] = null;
        }

        unset($data['logo_base64'], $data['remove_logo']);
        try {
            $profile->fill($data)->save();
        } catch (\Throwable $exception) {
            if ($newLogoPath) {
                Storage::disk('local')->delete($newLogoPath);
            }

            throw $exception;
        }

        if ($oldLogoPath !== $profile->logo_path && $profile->isManagedLogoPath($oldLogoPath)) {
            Storage::disk('local')->delete($oldLogoPath);
        }

        return $profile->fresh();
    }

    public function settings(Request $r, BranchSettings $settings)
    {
        return array_replace(
            $settings->defaults(),
            Setting::where('branch_id', $r->user()->branch_id)->pluck('value', 'key')->all(),
        );
    }

    public function updateSettings(Request $r)
    {
        $d = $r->validate([
            'settings' => 'required|array:pending_payment_minutes,kitchen_lead_minutes,delivery_lead_minutes,half_and_half_extra,additional_wing_flavor_extra,max_wing_flavors,delivery_zones,payment_methods,show_kitchen_prices,loyalty_enabled,loyalty_point_value',
            'settings.pending_payment_minutes' => 'sometimes|integer|min:1|max:120',
            'settings.kitchen_lead_minutes' => 'sometimes|integer|min:0|max:1440',
            'settings.delivery_lead_minutes' => 'sometimes|integer|min:0|max:1440',
            'settings.half_and_half_extra' => 'sometimes|numeric|min:0',
            'settings.additional_wing_flavor_extra' => 'sometimes|numeric|min:0',
            'settings.max_wing_flavors' => 'sometimes|integer|min:1|max:20',
            'settings.delivery_zones' => 'sometimes|array|max:100',
            'settings.delivery_zones.*.name' => 'required|string|max:100|distinct',
            'settings.delivery_zones.*.kind' => 'sometimes|in:colony,auxiliary',
            'settings.delivery_zones.*.fee' => 'required|numeric|min:0|max:100000',
            'settings.delivery_zones.*.active' => 'sometimes|boolean',
            'settings.payment_methods' => 'sometimes|array|max:2',
            'settings.payment_methods.*.key' => 'required|in:cash,transfer|distinct',
            'settings.payment_methods.*.label' => 'required|string|max:50',
            'settings.payment_methods.*.active' => 'sometimes|boolean',
            'settings.show_kitchen_prices' => 'sometimes|boolean',
            'settings.loyalty_enabled' => 'sometimes|boolean',
            'settings.loyalty_point_value' => 'sometimes|numeric|min:0|max:1000',
        ]);
        if (isset($d['settings']['payment_methods']) && ! collect($d['settings']['payment_methods'])->contains(fn ($method) => $method['active'] ?? true)) {
            throw ValidationException::withMessages([
                'settings.payment_methods' => 'Debe permanecer al menos un método de pago activo.',
            ]);
        }
        DB::transaction(function () use ($r, $d): void {
            foreach ($d['settings'] as $k => $v) {
                Setting::updateOrCreate(['branch_id' => $r->user()->branch_id, 'key' => $k], ['value' => $v]);
            }
        });

        return $this->settings($r, app(BranchSettings::class));
    }

    public function document(Request $r, Order $o, ReceiptService $s)
    {
        abort_unless($o->branch_id === $r->user()->branch_id, 404);
        $d = $r->validate(['type' => 'required|in:customer_html,customer_pdf,customer_image,kitchen,delivery']);
        $canOperatePos = $r->user()->hasPermission('pos.use');
        $allowed = match ($d['type']) {
            'kitchen' => $canOperatePos || $r->user()->hasPermission('kitchen.use'),
            'delivery' => $canOperatePos || $r->user()->hasPermission('delivery.use'),
            default => $canOperatePos,
        };
        abort_unless($allowed, 403, 'No tienes permiso para generar este tipo de documento.');
        $doc = $s->generate($o, $d['type'], $r->user());
        $downloadUrl = $doc->path
            ? URL::temporarySignedRoute('order-documents.download', now()->addMinutes(30), ['d' => $doc->id])
            : null;

        $whatsappText = match ($d['type']) {
            'kitchen' => "Comanda de cocina #{$o->daily_number}",
            'delivery' => "Reparto de orden #{$o->daily_number}",
            default => "Orden #{$o->daily_number} - $".number_format((float) $o->total, 2),
        };
        if ($downloadUrl) {
            $whatsappText .= "\n".$downloadUrl;
        }

        return response()->json($doc->toArray() + [
            'download_url' => $downloadUrl,
            'whatsapp_url' => 'https://wa.me/?text='.rawurlencode($whatsappText),
        ], 201);
    }

    public function download(Request $r, OrderDocument $d)
    {
        abort_unless($d->order_id && $d->path, 404);

        return Storage::download($d->path);
    }

    public function openCash(Request $r, BranchClock $clock)
    {
        $d = $r->validate(['date' => 'nullable|date', 'opening_amount' => 'required|numeric|min:0']);
        $localToday = $clock->today($r->user()->branch_id);
        $date = $d['date'] ?? $localToday->toDateString();
        if (CarbonImmutable::parse($date)->startOfDay()->gt($localToday)) {
            throw ValidationException::withMessages(['date' => 'No puedes abrir una caja con fecha futura en la sucursal.']);
        }
        $day = CashDay::firstOrCreate(
            ['branch_id' => $r->user()->branch_id, 'date' => $date],
            ['opened_by' => $r->user()->id, 'opening_amount' => $d['opening_amount']],
        );

        return response()->json($day, $day->wasRecentlyCreated ? 201 : 200);
    }

    public function cashMovement(Request $r, CashDay $day)
    {
        $d = $r->validate(['type' => 'required|in:income,expense', 'amount' => 'required|numeric|gt:0', 'category' => 'required|string', 'description' => 'nullable|string']);
        $d['user_id'] = $r->user()->id;
        $movement = DB::transaction(function () use ($r, $day, $d) {
            $day = CashDay::query()->whereKey($day->id)->lockForUpdate()->firstOrFail();
            $this->ownCashDay($r, $day);
            abort_if($day->closed_at, 422, 'La caja ya está cerrada.');

            return $day->movements()->create($d);
        });

        return response()->json($movement, 201);
    }

    public function closeCash(Request $r, CashDay $day, CashReportService $s)
    {
        $d = $r->validate(['actual_amount' => 'required|numeric|min:0']);
        $day = DB::transaction(function () use ($r, $day, $d, $s) {
            $day = CashDay::query()->whereKey($day->id)->lockForUpdate()->firstOrFail();
            $this->ownCashDay($r, $day);
            abort_if($day->closed_at, 422, 'La caja ya está cerrada.');
            $summary = $s->summary($day->branch_id, $day->date->toDateString());
            $day->update([
                'closed_by' => $r->user()->id,
                'expected_amount' => $summary['expected_cash'],
                'actual_amount' => $d['actual_amount'],
                'difference' => $d['actual_amount'] - $summary['expected_cash'],
                'closed_at' => now(),
            ]);

            return $day->fresh();
        });

        return $day;
    }

    public function cashReport(Request $r, CashReportService $s, BranchClock $clock)
    {
        $data = $r->validate(['date' => 'nullable|date']);

        return $s->summary($r->user()->branch_id, $data['date'] ?? $clock->today($r->user()->branch_id)->toDateString());
    }

    public function daily(Request $r, CashReportService $s, BranchClock $clock)
    {
        $date = $r->validate(['date' => 'nullable|date'])['date'] ?? $clock->today($r->user()->branch_id)->toDateString();
        $data = $s->summary($r->user()->branch_id, $date);
        $report = DailyReport::updateOrCreate(['branch_id' => $r->user()->branch_id, 'date' => $date], ['data' => $data]);
        $message = "Reporte diario {$date}\nVentas: $".number_format((float) $data['gross_sales'], 2)."\nPedidos: {$data['orders']}\nEfectivo esperado: $".number_format((float) $data['expected_cash'], 2)."\nCompras caja: $".number_format((float) $data['cash_purchases'], 2);

        return response()->json($report->toArray() + ['whatsapp_url' => 'https://wa.me/?text='.rawurlencode($message)]);
    }

    public function audits(Request $r)
    {
        return AuditLog::with('user')
            ->where('branch_id', $r->user()->branch_id)
            ->when($r->entity, fn ($q, $entity) => $q->where('auditable_type', 'like', "%{$entity}%"))
            ->latest()
            ->paginate(50);
    }

    private function ownCashDay(Request $request, CashDay $day): void
    {
        abort_unless($day->branch_id === $request->user()->branch_id, 404);
    }

    /** @return array{0: string, 1: string} */
    private function decodeLogo(string $encoded): array
    {
        $declaredMime = null;
        $payload = trim($encoded);
        if (str_starts_with(strtolower($payload), 'data:')) {
            if (! preg_match('#^data:(image/(?:png|jpe?g));base64,(.+)$#isD', $payload, $matches)) {
                throw ValidationException::withMessages([
                    'logo_base64' => 'El logo debe ser una imagen PNG o JPEG codificada en base64.',
                ]);
            }
            $declaredMime = strtolower($matches[1]) === 'image/jpg' ? 'image/jpeg' : strtolower($matches[1]);
            $payload = $matches[2];
        }

        $payload = preg_replace('/\s+/', '', $payload) ?? '';
        $contents = base64_decode($payload, true);
        if ($contents === false || $contents === '' || strlen($contents) > 1_048_576) {
            throw ValidationException::withMessages([
                'logo_base64' => 'El logo no es base64 válido o supera el límite de 1 MB.',
            ]);
        }

        $info = @getimagesizefromstring($contents);
        $mime = strtolower((string) ($info['mime'] ?? ''));
        if (! in_array($mime, ['image/png', 'image/jpeg'], true) || ($declaredMime && $declaredMime !== $mime)) {
            throw ValidationException::withMessages([
                'logo_base64' => 'El contenido del logo no corresponde a un PNG o JPEG válido.',
            ]);
        }

        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        if ($width < 1 || $height < 1 || $width > 2500 || $height > 2500 || $width * $height > 4_000_000) {
            throw ValidationException::withMessages([
                'logo_base64' => 'El logo debe medir como máximo 2500 px por lado y 4 megapíxeles.',
            ]);
        }

        return [$contents, $mime === 'image/png' ? 'png' : 'jpg'];
    }
}
