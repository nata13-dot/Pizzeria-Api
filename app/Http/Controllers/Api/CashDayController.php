<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CashDay;
use App\Models\CashMovement;
use App\Services\BranchClock;
use App\Services\CashReportService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashDayController extends Controller
{
    public function index(Request $request): LengthAwarePaginator
    {
        $filters = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', 'in:open,closed'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        if (isset($filters['date']) && (isset($filters['from']) || isset($filters['to']))) {
            throw ValidationException::withMessages([
                'date' => 'Usa una fecha exacta o un rango, no ambos.',
            ]);
        }

        $days = CashDay::query()
            ->where('branch_id', $request->user()->branch_id)
            ->with(['opener:id,name', 'closer:id,name'])
            ->withCount('movements')
            ->when($filters['date'] ?? null, fn ($query, $date) => $query->whereDate('date', $date))
            ->when($filters['from'] ?? null, fn ($query, $date) => $query->whereDate('date', '>=', $date))
            ->when($filters['to'] ?? null, fn ($query, $date) => $query->whereDate('date', '<=', $date))
            ->when(($filters['status'] ?? null) === 'open', fn ($query) => $query->whereNull('closed_at'))
            ->when(($filters['status'] ?? null) === 'closed', fn ($query) => $query->whereNotNull('closed_at'))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 30)
            ->withQueryString();

        $days->setCollection(
            $days->getCollection()->map(fn (CashDay $day): array => $this->serializeDay($day)),
        );

        return $days;
    }

    public function current(Request $request, BranchClock $clock, CashReportService $reports): array
    {
        $date = $clock->today($request->user()->branch_id)->toDateString();
        $day = $this->dayForBranchAndDate($request->user()->branch_id, $date);

        if (! $day) {
            return $this->emptyDay($date, $reports->summary($request->user()->branch_id, $date));
        }

        return $this->detailedDay($day, $reports);
    }

    public function show(Request $request, CashDay $day, CashReportService $reports): array
    {
        $this->ensureOwnedByBranch($request, $day);

        return $this->detailedDay($day, $reports);
    }

    public function open(Request $request, BranchClock $clock)
    {
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'opening_amount' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
        ]);
        $branchId = (int) $request->user()->branch_id;
        $localToday = $clock->today($branchId)->toDateString();
        $date = $data['date'] ?? $localToday;
        if ($date > $localToday) {
            throw ValidationException::withMessages([
                'date' => 'No puedes abrir una caja con fecha futura en la sucursal.',
            ]);
        }

        $day = DB::transaction(function () use ($branchId, $date, $data, $request): CashDay {
            // Serializing openings on the branch avoids a unique-key race when
            // two terminals try to open the same business day simultaneously.
            Branch::query()->whereKey($branchId)->lockForUpdate()->firstOrFail();

            $existing = CashDay::query()
                ->where('branch_id', $branchId)
                ->whereDate('date', $date)
                ->first();

            return $existing ?? CashDay::create([
                'branch_id' => $branchId,
                'date' => $date,
                'opened_by' => $request->user()->id,
                'opening_amount' => $data['opening_amount'],
            ]);
        });

        return response()->json($day, $day->wasRecentlyCreated ? 201 : 200);
    }

    public function storeMovement(Request $request, CashDay $day)
    {
        $data = $request->validate([
            'type' => ['required', 'in:income,expense'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'],
            'category' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'required_if:type,expense', 'string', 'max:2000'],
        ]);
        $data['user_id'] = $request->user()->id;

        $movement = DB::transaction(function () use ($request, $day, $data): CashMovement {
            $lockedDay = CashDay::query()->whereKey($day->id)->lockForUpdate()->firstOrFail();
            $this->ensureOwnedByBranch($request, $lockedDay);
            abort_if($lockedDay->closed_at, 422, 'La caja ya está cerrada.');

            return $lockedDay->movements()->create($data);
        });

        return response()->json($movement, 201);
    }

    public function close(Request $request, CashDay $day, CashReportService $reports): CashDay
    {
        $data = $request->validate([
            'actual_amount' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
        ]);

        return DB::transaction(function () use ($request, $day, $data, $reports): CashDay {
            $lockedDay = CashDay::query()->whereKey($day->id)->lockForUpdate()->firstOrFail();
            $this->ensureOwnedByBranch($request, $lockedDay);
            abort_if($lockedDay->closed_at, 422, 'La caja ya está cerrada.');

            $summary = $reports->summary($lockedDay->branch_id, $lockedDay->date->toDateString());
            $lockedDay->update([
                'closed_by' => $request->user()->id,
                'expected_amount' => $summary['expected_cash'],
                'actual_amount' => $data['actual_amount'],
                'difference' => (float) $data['actual_amount'] - $summary['expected_cash'],
                'closed_at' => now(),
            ]);

            return $lockedDay->fresh();
        });
    }

    private function dayForBranchAndDate(int $branchId, string $date): ?CashDay
    {
        return CashDay::query()
            ->where('branch_id', $branchId)
            ->whereDate('date', $date)
            ->first();
    }

    private function detailedDay(CashDay $day, CashReportService $reports): array
    {
        $day->loadMissing([
            'opener:id,name',
            'closer:id,name',
            'movements' => fn ($query) => $query->with('user:id,name')->orderBy('created_at')->orderBy('id'),
        ]);

        return $this->serializeDay($day, true) + [
            'summary' => $reports->summary($day->branch_id, $day->date->toDateString()),
        ];
    }

    private function serializeDay(CashDay $day, bool $withMovements = false): array
    {
        $payload = [
            'id' => $day->id,
            'date' => $day->date->toDateString(),
            'status' => $day->closed_at ? 'closed' : 'open',
            'is_closed' => (bool) $day->closed_at,
            'opening_amount' => (float) $day->opening_amount,
            'expected_amount' => $day->closed_at ? (float) $day->expected_amount : null,
            'actual_amount' => $day->actual_amount === null ? null : (float) $day->actual_amount,
            'difference' => $day->difference === null ? null : (float) $day->difference,
            'opened_at' => $day->created_at?->toISOString(),
            'closed_at' => $day->closed_at?->toISOString(),
            'opened_by' => $day->relationLoaded('opener') && $day->opener ? [
                'id' => $day->opener->id,
                'name' => $day->opener->name,
            ] : null,
            'closed_by' => $day->relationLoaded('closer') && $day->closer ? [
                'id' => $day->closer->id,
                'name' => $day->closer->name,
            ] : null,
            'movements_count' => (int) ($day->movements_count ?? ($day->relationLoaded('movements') ? $day->movements->count() : 0)),
        ];

        if ($withMovements) {
            $payload['movements'] = $day->movements->map(fn (CashMovement $movement): array => [
                'id' => $movement->id,
                'type' => $movement->type,
                'amount' => (float) $movement->amount,
                'category' => $movement->category,
                'description' => $movement->description,
                'reference_type' => $movement->reference_type,
                'reference_id' => $movement->reference_id,
                'created_at' => $movement->created_at?->toISOString(),
                'user' => $movement->user ? [
                    'id' => $movement->user->id,
                    'name' => $movement->user->name,
                ] : null,
            ])->values()->all();
        }

        return $payload;
    }

    private function emptyDay(string $date, array $summary): array
    {
        return [
            'id' => null,
            'date' => $date,
            'status' => 'not_opened',
            'is_closed' => false,
            'opening_amount' => 0.0,
            'expected_amount' => null,
            'actual_amount' => null,
            'difference' => null,
            'opened_at' => null,
            'closed_at' => null,
            'opened_by' => null,
            'closed_by' => null,
            'movements_count' => 0,
            'movements' => [],
            'summary' => $summary,
        ];
    }

    private function ensureOwnedByBranch(Request $request, CashDay $day): void
    {
        abort_unless($day->branch_id === $request->user()->branch_id, 404);
    }
}
