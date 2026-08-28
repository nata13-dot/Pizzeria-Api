<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\LoyaltyRule;
use App\Models\Order;
use App\Services\LoyaltyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => 'nullable|string|max:100',
            'active' => 'nullable|boolean',
        ]);

        return Customer::with('addresses')
            ->withSum('loyaltyTransactions as points_balance', 'points')
            ->where('branch_id', $request->user()->branch_id)
            ->when(
                $filters['search'] ?? null,
                fn ($query, $search) => $query->where(
                    fn ($customerQuery) => $customerQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"),
                ),
            )
            ->when(array_key_exists('active', $filters), fn (Builder $query) => $query->where('active', $filters['active']))
            ->orderBy('name')
            ->paginate(25);
    }

    public function store(Request $request)
    {
        $data = $this->data($request);
        $data['branch_id'] = $request->user()->branch_id;

        return response()->json(Customer::create($data), 201);
    }

    public function show(Request $request, Customer $c, LoyaltyService $loyalty)
    {
        $this->own($request, $c);
        $loyalty->expire($c);

        return $c->load(['addresses', 'orders.items', 'loyaltyTransactions'])->append('points_balance');
    }

    public function update(Request $request, Customer $c)
    {
        $this->own($request, $c);
        $c->update($this->data($request, true));

        return $c;
    }

    public function address(Request $request, Customer $c)
    {
        $this->own($request, $c);
        $data = $request->validate([
            'label' => 'required|string|max:80',
            'address' => 'required|string|max:1000',
            'references' => 'nullable|string',
            'map_url' => 'nullable|url|max:2048',
            'delivery_zone' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:1000',
            'is_default' => 'sometimes|boolean',
        ]);
        if ($data['is_default'] ?? false) {
            $c->addresses()->update(['is_default' => false]);
        }
        $data['is_default'] = ($data['is_default'] ?? false) || ! $c->addresses()->exists();

        return response()->json($c->addresses()->create($data), 201);
    }

    public function updateAddress(Request $request, Customer $c, CustomerAddress $a)
    {
        $this->own($request, $c);
        abort_unless($a->customer_id === $c->id, 404);
        $data = $request->validate([
            'label' => 'sometimes|required|string|max:80',
            'address' => 'sometimes|required|string|max:1000',
            'references' => 'nullable|string',
            'map_url' => 'nullable|url|max:2048',
            'delivery_zone' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:1000',
            'is_default' => 'sometimes|boolean',
        ]);
        if ($data['is_default'] ?? false) {
            $c->addresses()->whereKeyNot($a->id)->update(['is_default' => false]);
        }
        $a->update($data);

        return $a;
    }

    public function destroyAddress(Request $request, Customer $c, CustomerAddress $a)
    {
        $this->own($request, $c);
        abort_unless($a->customer_id === $c->id, 404);
        $a->delete();

        if ($a->is_default) {
            $c->addresses()->oldest('id')->first()?->update(['is_default' => true]);
        }

        return response()->noContent();
    }

    public function orders(Request $request, Customer $c)
    {
        $this->own($request, $c);

        return $c->orders()
            ->with(['items.flavors.flavor', 'items.modifiers', 'items.components', 'payments', 'delivery'])
            ->latest('created_at')
            ->paginate(25);
    }

    public function loyalty(Request $request, Customer $c, LoyaltyService $loyalty)
    {
        $this->own($request, $c);
        $loyalty->expire($c);

        return [
            'points_balance' => $c->fresh()->points_balance,
            'transactions' => $c->loyaltyTransactions()->with(['rule', 'order'])->latest()->paginate(50),
            'redemptions' => $c->loyaltyRedemptions()->with('order')->latest()->limit(50)->get(),
        ];
    }

    public function rules(Request $request)
    {
        return LoyaltyRule::where('branch_id', $request->user()->branch_id)->get();
    }

    public function storeRule(Request $request)
    {
        $data = $this->ruleData($request);
        $data['branch_id'] = $request->user()->branch_id;
        $data['threshold'] ??= 1;

        return response()->json(LoyaltyRule::create($data), 201);
    }

    public function updateRule(Request $request, LoyaltyRule $rule)
    {
        $this->ownRule($request, $rule);
        $rule->update($this->ruleData($request, $rule, true));

        return $rule->fresh();
    }

    public function destroyRule(Request $request, LoyaltyRule $rule)
    {
        $this->ownRule($request, $rule);
        $rule->update(['active' => false]);

        return response()->noContent();
    }

    public function adjustPoints(Request $request, Customer $c, LoyaltyService $loyalty)
    {
        $this->own($request, $c);
        $data = $request->validate([
            'points' => 'required|numeric|not_in:0',
            'comment' => 'required|string|max:1000',
            'expires_at' => 'nullable|date|after:now',
        ]);

        return response()->json($loyalty->adjust(
            $c,
            (float) $data['points'],
            $request->user(),
            $data['comment'],
            $data['expires_at'] ?? null,
        ), 201);
    }

    public function redeem(Request $request, Customer $c, LoyaltyService $loyalty)
    {
        $this->own($request, $c);
        $branchId = $request->user()->branch_id;
        $data = $request->validate([
            'points' => 'required|numeric|gt:0',
            'order_id' => [
                'required',
                Rule::exists('orders', 'id')->where(
                    fn ($query) => $query
                        ->where('branch_id', $branchId)
                        ->where('customer_id', $c->id)
                        ->whereIn('status', ['draft', 'pending_payment']),
                ),
            ],
        ]);
        $order = Order::where('branch_id', $branchId)
            ->where('customer_id', $c->id)
            ->whereIn('status', ['draft', 'pending_payment'])
            ->findOrFail($data['order_id']);

        return response()->json(
            $loyalty->redeem($c, $data['points'], $request->user(), $order),
            201,
        );
    }

    private function data(Request $request, bool $partial = false): array
    {
        $sometimes = $partial ? 'sometimes|' : '';

        return $request->validate([
            'name' => $sometimes.'required|string|max:150',
            'phone' => $sometimes.'required|string|max:30',
            'email' => 'nullable|email',
            'birth_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'active' => 'sometimes|boolean',
        ]);
    }

    private function own(Request $request, Customer $customer): void
    {
        abort_unless($customer->branch_id === $request->user()->branch_id, 404);
    }

    private function ownRule(Request $request, LoyaltyRule $rule): void
    {
        abort_unless($rule->branch_id === $request->user()->branch_id, 404);
    }

    private function ruleData(Request $request, ?LoyaltyRule $rule = null, bool $partial = false): array
    {
        $type = $request->input('type', $rule?->type);
        $requiresIds = in_array($type, ['product', 'category'], true);
        $conditionsTable = $type === 'category' ? 'product_categories' : 'products';
        $sometimes = $partial ? 'sometimes|' : '';
        $conditionsRule = $requiresIds && ! $partial ? 'required|' : 'nullable|';

        return $request->validate([
            'name' => $sometimes.'required|string|max:150',
            'type' => $sometimes.'required|in:per_amount,per_order,product,category,promotion,birthday',
            'threshold' => ($type === 'per_amount' && ! $partial ? 'required|' : 'sometimes|').'numeric|gt:0',
            'points' => $sometimes.'required|numeric|gt:0',
            'expires_days' => 'nullable|integer|min:1|max:3650',
            'conditions' => $conditionsRule.'array:ids,starts_at,ends_at',
            'conditions.ids' => ($requiresIds && ! $partial ? 'required|' : 'sometimes|').'array|min:1',
            'conditions.ids.*' => [
                'integer',
                'distinct',
                Rule::exists($conditionsTable, 'id')->where(
                    fn ($query) => $query->where('branch_id', $request->user()->branch_id),
                ),
            ],
            'conditions.starts_at' => 'nullable|date',
            'conditions.ends_at' => 'nullable|date|after_or_equal:conditions.starts_at',
            'courtesy_eligible' => 'sometimes|boolean',
            'active' => 'sometimes|boolean',
        ]);
    }
}
