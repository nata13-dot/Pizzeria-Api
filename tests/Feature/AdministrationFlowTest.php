<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\CashDay;
use App\Models\Order;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\User;
use App\Services\CashReportService;
use App\Services\ReceiptService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdministrationFlowTest extends TestCase
{
    use RefreshDatabase;

    private function order(User $u): Order
    {
        $o = Order::create(['branch_id' => $u->branch_id, 'user_id' => $u->id, 'order_date' => today(), 'daily_number' => 1, 'status' => 'delivered', 'type' => 'pickup', 'subtotal' => 200, 'total' => 200]);
        $o->items()->create(['name' => 'Pizza grande', 'quantity' => 1, 'unit_price' => 200, 'total' => 200]);
        $o->payments()->create(['method' => 'cash', 'amount' => 200, 'user_id' => $u->id]);

        return $o;
    }

    public function test_receipts_are_generated_only_when_requested(): void
    {
        Storage::fake('local');
        $this->seed();
        $u = User::first();
        $o = $this->order($u);
        BusinessProfile::create(['branch_id' => $u->branch_id, 'name' => 'Pizza Prueba']);
        $this->assertDatabaseCount('order_documents', 0);
        $service = app(ReceiptService::class);
        foreach (['customer_html', 'customer_pdf', 'customer_image'] as $type) {
            $doc = $service->generate($o, $type, $u);
            Storage::disk('local')->assertExists($doc->path);
            if ($type === 'customer_image') {
                $this->assertStringEndsWith('.png', $doc->path);
            }
        }$this->assertDatabaseCount('order_documents', 3);
    }

    public function test_cash_report_separates_owner_purchase_and_audits_changes(): void
    {
        $this->seed();
        $u = User::first();
        $this->order($u);
        CashDay::create(['branch_id' => $u->branch_id, 'date' => today(), 'opened_by' => $u->id, 'opening_amount' => 100]);
        Purchase::create(['branch_id' => $u->branch_id, 'user_id' => $u->id, 'purchased_at' => today(), 'payment_source' => 'cash', 'total' => 50]);
        Purchase::create(['branch_id' => $u->branch_id, 'user_id' => $u->id, 'purchased_at' => today(), 'payment_source' => 'owner', 'total' => 80]);
        $summary = app(CashReportService::class)->summary($u->branch_id, today()->toDateString());
        $this->assertEquals(50, $summary['cash_purchases']);
        $this->assertEquals(250, $summary['expected_cash']);

        $cancelled = Order::create(['branch_id' => $u->branch_id, 'user_id' => $u->id, 'order_date' => today(), 'daily_number' => 2, 'status' => 'cancelled', 'type' => 'pickup', 'subtotal' => 999, 'discount' => 50, 'total' => 949]);
        $cancelled->payments()->create(['method' => 'cash', 'amount' => 949, 'user_id' => $u->id]);
        $afterCancellation = app(CashReportService::class)->summary($u->branch_id, today()->toDateString());
        $this->assertEquals(200, $afterCancellation['cash']);
        $this->assertEquals(200, $afterCancellation['gross_sales']);
        $this->assertEquals(0, $afterCancellation['discounts']);
        $this->assertEquals(250, $afterCancellation['expected_cash']);
        $o = Order::first();
        $o->update(['notes' => 'Cambio auditado']);
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => Order::class, 'auditable_id' => $o->id, 'action' => 'updated']);
    }

    public function test_daily_report_returns_manual_whatsapp_link(): void
    {
        $this->seed();
        $u = User::first();
        Sanctum::actingAs($u);
        $this->order($u);
        $this->postJson('/api/reports/daily', ['date' => today()->toDateString()])->assertOk()->assertJsonPath('data.orders', 1)->assertJson(fn ($json) => $json->whereType('whatsapp_url', 'string')->etc());
    }

    public function test_cash_defaults_use_the_branch_date_near_utc_midnight(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-26 01:00:00 UTC'));
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->postJson('/api/cash-days/open', ['opening_amount' => 50])
            ->assertCreated()
            ->assertJsonPath('date', '2026-08-25T00:00:00.000000Z');
        $this->getJson('/api/reports/cash-day')
            ->assertOk()
            ->assertJsonPath('date', '2026-08-25');
        $this->postJson('/api/reports/daily')
            ->assertOk()
            ->assertJsonPath('date', '2026-08-25T00:00:00.000000Z');
        $this->postJson('/api/cash-days/open', ['date' => '2026-08-26', 'opening_amount' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');
        $this->travelBack();
    }

    public function test_kitchen_and_delivery_can_generate_operational_documents(): void
    {
        $this->seed();
        $u = User::first();
        $o = $this->order($u);
        $branch = $u->branch_id;
        $kitchen = User::create(['name' => 'Cocina', 'email' => 'cocina-doc@test.local', 'password' => 'password', 'role_id' => Role::where('slug', 'cocina')->value('id'), 'branch_id' => $branch]);
        Sanctum::actingAs($kitchen);
        $kitchenDocument = $this->postJson("/api/orders/{$o->id}/generate-document", ['type' => 'kitchen'])
            ->assertCreated()
            ->assertJsonPath('type', 'kitchen');
        $this->assertStringNotContainsString('$200.00', $kitchenDocument->json('content'));
        $delivery = User::create(['name' => 'Reparto', 'email' => 'reparto-doc@test.local', 'password' => 'password', 'role_id' => Role::where('slug', 'repartidor')->value('id'), 'branch_id' => $branch]);
        Sanctum::actingAs($delivery);
        $this->postJson("/api/orders/{$o->id}/generate-document", ['type' => 'delivery'])->assertCreated()->assertJsonPath('type', 'delivery');
    }

    public function test_customer_document_uses_a_temporary_signed_download(): void
    {
        Storage::fake('local');
        $this->seed();
        $u = User::first();
        $o = $this->order($u);
        Sanctum::actingAs($u);
        $response = $this->postJson("/api/orders/{$o->id}/generate-document", ['type' => 'customer_pdf'])->assertCreated();
        $url = $response->json('download_url');
        $this->assertNotNull($url);
        $this->get($url)->assertOk();
        $this->get("/api/order-documents/{$response->json('id')}/download")->assertForbidden();
    }
}
