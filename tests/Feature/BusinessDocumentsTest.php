<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Customer;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductFlavor;
use App\Models\Setting;
use App\Models\User;
use App\Services\ReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BusinessDocumentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_profile_validates_stores_replaces_and_removes_a_local_logo(): void
    {
        Storage::fake('local');
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        Sanctum::actingAs($admin);

        $response = $this->putJson('/api/business-profile', $this->profilePayload() + [
            'logo_base64' => 'data:image/png;base64,'.$this->imageBase64('png'),
        ])->assertOk()
            ->assertJsonPath('primary_color', '#D24B32')
            ->assertJsonPath('secondary_color', '#29231F')
            ->assertJsonPath('show_business_details', true)
            ->assertJsonPath('social_links.0.name', 'Instagram');
        $this->assertStringStartsWith('data:image/png;base64,', $response->json('logo_data_url'));
        $response->assertJsonMissingPath('logo_path');

        $profile = BusinessProfile::where('branch_id', $admin->branch_id)->firstOrFail();
        $firstPath = $profile->logo_path;
        $this->assertNotNull($firstPath);
        Storage::disk('local')->assertExists($firstPath);

        $jpeg = $this->putJson('/api/business-profile', $this->profilePayload() + [
            'logo_base64' => $this->imageBase64('jpeg'),
        ])->assertOk();
        $this->assertStringStartsWith('data:image/jpeg;base64,', $jpeg->json('logo_data_url'));
        Storage::disk('local')->assertMissing($firstPath);
        $secondPath = $profile->fresh()->logo_path;
        Storage::disk('local')->assertExists($secondPath);

        $this->putJson('/api/business-profile', $this->profilePayload() + ['remove_logo' => true])
            ->assertOk()
            ->assertJsonPath('logo_data_url', null);
        Storage::disk('local')->assertMissing($secondPath);
        $this->putJson('/api/business-profile', array_replace($this->profilePayload(), [
            'primary_color' => null,
            'secondary_color' => null,
        ]))->assertOk()
            ->assertJsonPath('primary_color', '#cf4b32')
            ->assertJsonPath('secondary_color', '#29231f');

        $this->putJson('/api/business-profile', array_replace($this->profilePayload(), ['primary_color' => 'red;}</style>']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('primary_color');
        $this->putJson('/api/business-profile', $this->profilePayload() + [
            'logo_base64' => 'data:image/png;base64,'.base64_encode('<svg><script>alert(1)</script></svg>'),
        ])->assertUnprocessable()->assertJsonValidationErrors('logo_base64');
        $this->putJson('/api/business-profile', array_replace($this->profilePayload(), [
            'social_links' => [
                ['name' => 'Instagram', 'value' => '@uno'],
                ['name' => 'instagram', 'value' => '@dos'],
            ],
        ]))->assertUnprocessable()->assertJsonValidationErrors('social_links');
    }

    public function test_customer_documents_are_complete_escaped_and_real_html_pdf_and_png_files(): void
    {
        Storage::fake('local');
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        Sanctum::actingAs($admin);
        $this->putJson('/api/business-profile', $this->profilePayload() + [
            'logo_base64' => 'data:image/png;base64,'.$this->imageBase64('png'),
        ])->assertOk();
        $order = $this->richDeliveryOrder($admin);

        $htmlResponse = $this->postJson("/api/orders/{$order->id}/generate-document", ['type' => 'customer_html'])
            ->assertCreated()
            ->assertJsonPath('type', 'customer_html')
            ->assertJson(fn ($json) => $json->whereType('print_content', 'string')->etc());
        $html = Storage::disk('local')->get($htmlResponse->json('path'));
        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString('Pizzería Documento', $html);
        $this->assertStringContainsString('Instagram: @pizza-documento', $html);
        $this->assertStringContainsString('Cliente &lt;script&gt;', $html);
        $this->assertStringContainsString('Tel. 555-0199', $html);
        $this->assertStringContainsString('Calle &lt;principal&gt; 123', $html);
        $this->assertStringContainsString('Pizza &lt;Especial&gt;', $html);
        $this->assertStringContainsString('• Extra &amp; queso', $html);
        $this->assertStringContainsString('Subtotal', $html);
        $this->assertStringContainsString('$200.00', $html);
        $this->assertStringContainsString('Descuento', $html);
        $this->assertStringContainsString('Envío', $html);
        $this->assertStringContainsString('$220.00', $html);
        $this->assertStringContainsString('Efectivo', $html);
        $this->assertStringContainsString('Transferencia', $html);
        $this->assertStringContainsString('Gracias por elegirnos', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('signature=', $htmlResponse->json('download_url'));
        $whatsappQuery = [];
        parse_str((string) parse_url($htmlResponse->json('whatsapp_url'), PHP_URL_QUERY), $whatsappQuery);
        $this->assertStringContainsString($htmlResponse->json('download_url'), $whatsappQuery['text']);

        $pdfResponse = $this->postJson("/api/orders/{$order->id}/generate-document", ['type' => 'customer_pdf'])
            ->assertCreated();
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($pdfResponse->json('path')));
        $imageResponse = $this->postJson("/api/orders/{$order->id}/generate-document", ['type' => 'customer_image'])
            ->assertCreated();
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", Storage::disk('local')->get($imageResponse->json('path')));

        $profile = BusinessProfile::where('branch_id', $admin->branch_id)->firstOrFail();
        $profile->update(['show_business_details' => false]);
        $hidden = app(ReceiptService::class)->customerHtml($order->fresh(), $profile->fresh());
        $this->assertStringNotContainsString('Pizzería Documento', $hidden);
        $this->assertStringNotContainsString('data:image/png;base64,', $hidden);
        $this->assertStringNotContainsString('@pizza-documento', $hidden);
        $this->assertStringContainsString('Gracias por elegirnos', $hidden);
    }

    public function test_kitchen_document_hides_prices_by_default_and_respects_the_existing_setting(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        $kitchen = User::where('email', 'cocina@pizzeria.local')->firstOrFail();
        $order = $this->richDeliveryOrder($admin);
        Sanctum::actingAs($kitchen);

        $hidden = $this->postJson("/api/orders/{$order->id}/generate-document", ['type' => 'kitchen'])
            ->assertCreated()
            ->json('content');
        $this->assertStringContainsString('Comanda de cocina', $hidden);
        $this->assertStringContainsString('Domicilio', $hidden);
        $this->assertStringContainsString('Prioridad:', $hidden);
        $this->assertStringContainsString('Programada', $hidden);
        $this->assertStringContainsString('Extra &amp; queso', $hidden);
        $this->assertStringContainsString('Preparación:', $hidden);
        $this->assertStringNotContainsString('$200.00', $hidden);
        $this->assertStringNotContainsString('$220.00', $hidden);

        Setting::updateOrCreate(
            ['branch_id' => $admin->branch_id, 'key' => 'show_kitchen_prices'],
            ['value' => true],
        );
        $visible = $this->postJson("/api/orders/{$order->id}/generate-document", ['type' => 'kitchen'])
            ->assertCreated()
            ->json('content');
        $this->assertStringContainsString('$200.00', $visible);
        $this->assertStringContainsString('$220.00', $visible);
    }

    public function test_delivery_document_shows_route_payment_state_and_outstanding_balance(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        $deliveryUser = User::where('email', 'repartidor@pizzeria.local')->firstOrFail();
        $order = $this->richDeliveryOrder($admin);
        $order->payments()->where('method', 'transfer')->delete();
        $order->update(['collect_on_delivery' => true]);
        Sanctum::actingAs($deliveryUser);

        $response = $this->postJson("/api/orders/{$order->id}/generate-document", ['type' => 'delivery'])
            ->assertCreated()
            ->assertJsonPath('path', null)
            ->assertJsonPath('download_url', null);
        $html = $response->json('content');
        $this->assertStringContainsString('Zona Centro', $html);
        $this->assertStringContainsString('Portón &lt;azul&gt;', $html);
        $this->assertStringContainsString('https://maps.example.test/ubicacion', $html);
        $this->assertStringContainsString('Método de pago:</strong> Efectivo, Contra entrega', $html);
        $this->assertStringContainsString('Estado de pago:</strong> Pago parcial', $html);
        $this->assertStringContainsString('Saldo por cobrar: $120.00', $html);
        $this->assertStringContainsString('Notas de reparto:', $html);
        $this->assertStringContainsString('&lt;script&gt;llamar antes&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>llamar antes</script>', $html);
    }

    private function richDeliveryOrder(User $user): Order
    {
        $customer = Customer::create([
            'branch_id' => $user->branch_id,
            'name' => 'Cliente <script>',
            'phone' => '555-0199',
        ]);
        $order = Order::create([
            'branch_id' => $user->branch_id,
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'order_date' => today(),
            'daily_number' => 37,
            'status' => 'confirmed',
            'type' => 'delivery',
            'scheduled_at' => now()->addHour(),
            'subtotal' => 200,
            'discount' => 10,
            'delivery_fee' => 30,
            'total' => 220,
            'notes' => '<script>llamar antes</script>',
        ]);
        $product = Product::create(['branch_id' => $user->branch_id, 'name' => 'Producto documento', 'type' => 'pizza']);
        $flavor = ProductFlavor::create(['product_id' => $product->id, 'name' => 'Pepperoni <picante>']);
        $modifier = Modifier::create(['branch_id' => $user->branch_id, 'name' => 'Extra & queso', 'type' => 'add', 'price' => 15]);
        $item = $order->items()->create([
            'name' => 'Pizza <Especial>',
            'quantity' => 1,
            'unit_price' => 200,
            'total' => 200,
            'notes' => 'Preparar <bien cocida>',
        ]);
        $item->flavors()->create(['product_flavor_id' => $flavor->id, 'ratio' => 1]);
        $item->modifiers()->create(['modifier_id' => $modifier->id, 'name' => $modifier->name, 'price' => 15]);
        $item->components()->create([
            'name' => 'Mitad hawaiana',
            'quantity' => 1,
            'flavors' => ['Hawaiana', 'Queso <extra>'],
            'modifiers' => [['name' => 'Orilla <rellena>', 'price' => 20]],
            'notes' => 'Sin <cebolla>',
        ]);
        $order->payments()->create(['method' => 'cash', 'amount' => 100, 'reference' => null, 'user_id' => $user->id]);
        $order->payments()->create(['method' => 'transfer', 'amount' => 120, 'reference' => 'TRX-123', 'user_id' => $user->id]);
        $order->delivery()->create([
            'recipient' => 'Ana <img>',
            'phone' => '555-0110',
            'address' => 'Calle <principal> 123',
            'references' => 'Portón <azul>',
            'map_url' => 'https://maps.example.test/ubicacion',
            'delivery_zone' => 'Zona Centro',
        ]);

        return $order->fresh();
    }

    private function profilePayload(): array
    {
        return [
            'name' => 'Pizzería Documento',
            'phone' => '555-0101',
            'address' => 'Avenida Principal 10',
            'primary_color' => '#D24B32',
            'secondary_color' => '#29231F',
            'tax_id' => 'RFC-PRUEBA',
            'social_links' => [
                ['name' => 'Instagram', 'value' => '@pizza-documento'],
                ['name' => 'Facebook', 'value' => 'Pizza Documento'],
            ],
            'show_business_details' => true,
            'receipt_footer' => 'Gracias por elegirnos',
        ];
    }

    private function imageBase64(string $format): string
    {
        $image = imagecreatetruecolor(24, 16);
        $background = imagecolorallocate($image, 210, 75, 50);
        imagefilledrectangle($image, 0, 0, 23, 15, $background);
        ob_start();
        if ($format === 'jpeg') {
            imagejpeg($image, null, 85);
        } else {
            imagepng($image);
        }
        $contents = (string) ob_get_clean();
        imagedestroy($image);

        return base64_encode($contents);
    }
}
