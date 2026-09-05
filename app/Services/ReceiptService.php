<?php

namespace App\Services;

use App\Models\BusinessProfile;
use App\Models\Order;
use App\Models\OrderDocument;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class ReceiptService
{
    private const DOCUMENT_TYPES = ['customer_html', 'customer_pdf', 'customer_image', 'kitchen', 'delivery'];

    public function __construct(private readonly BranchSettings $settings) {}

    public function generate(Order $order, string $type, $user): OrderDocument
    {
        if (! in_array($type, self::DOCUMENT_TYPES, true)) {
            throw new InvalidArgumentException('Tipo de documento no soportado.');
        }

        $this->loadOrder($order);
        $profile = BusinessProfile::firstOrCreate(
            ['branch_id' => $order->branch_id],
            ['name' => 'Pizzería POS'],
        )->fresh();
        $content = match ($type) {
            'kitchen' => $this->kitchenHtml($order, $profile, $user->receipt_font_size ?? 'small'),
            'delivery' => $this->deliveryHtml($order, $profile, $user->receipt_font_size ?? 'small'),
            default => $this->customerHtml($order, $profile, $user->receipt_font_size ?? 'small'),
        };

        $path = null;
        if (str_starts_with($type, 'customer_')) {
            $directory = 'receipts/'.$order->order_date->format('Y-m-d');
            $base = $directory.'/order-'.$order->id.'-'.Str::uuid();
            [$path, $contents] = match ($type) {
                'customer_html' => [$base.'.html', $content],
                'customer_pdf' => [$base.'.pdf', $this->pdf($content, $order)],
                'customer_image' => [$base.'.png', $this->png($order, $profile, $user->receipt_font_size ?? 'small')],
            };
            if (! Storage::disk('local')->put($path, $contents)) {
                throw new RuntimeException('No se pudo almacenar el documento de la orden.');
            }
        }

        return OrderDocument::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'type' => $type,
            'path' => $path,
            'content' => $path ? null : $content,
        ]);
    }

    public function customerHtml(Order $order, BusinessProfile $profile, string $receiptFontSize = 'small'): string
    {
        $this->loadOrder($order);
        $payment = $this->paymentData($order);

        return view('documents.customer', $this->commonData($order, $profile, $receiptFontSize) + [
            'documentTitle' => 'Nota de venta #'.$order->daily_number,
            'createdDate' => $order->created_at->format('d/m/Y'),
            'createdTime' => $order->created_at->format('H:i'),
            'customerName' => $order->customer?->name ?: $order->contact_name,
            'customerPhone' => $order->customer?->phone ?: ($order->contact_phone ?: $order->delivery?->phone),
            'recipient' => $order->delivery?->recipient,
            'deliveryAddress' => $order->delivery?->address,
            'subtotal' => (float) $order->subtotal,
            'discount' => (float) $order->discount,
            'deliveryFee' => (float) $order->delivery_fee,
            'total' => (float) $order->total,
            'payments' => $payment['payments'],
            'paymentNote' => $payment['customer_note'],
        ])->render();
    }

    public function kitchenHtml(Order $order, BusinessProfile $profile, string $receiptFontSize = 'small'): string
    {
        $this->loadOrder($order);
        $showPrices = (bool) $this->settings->get($order->branch_id, 'show_kitchen_prices');

        return view('documents.kitchen', $this->commonData($order, $profile, $receiptFontSize) + [
            'documentTitle' => 'Comanda de cocina #'.$order->daily_number,
            'createdTime' => $order->created_at->format('H:i'),
            'orderType' => $this->orderType($order->type),
            'priority' => $order->scheduled_at ? 'Programada' : 'Normal',
            'scheduledAt' => $order->scheduled_at?->format('d/m/Y H:i'),
            'orderNotes' => $order->notes,
            'showPrices' => $showPrices,
            'total' => (float) $order->total,
        ])->render();
    }

    public function deliveryHtml(Order $order, BusinessProfile $profile, string $receiptFontSize = 'small'): string
    {
        $this->loadOrder($order);
        $payment = $this->paymentData($order);
        $delivery = $order->delivery;

        return view('documents.delivery', $this->commonData($order, $profile, $receiptFontSize) + [
            'documentTitle' => 'Hoja de reparto #'.$order->daily_number,
            'customerName' => $order->customer?->name,
            'customerPhone' => $order->customer?->phone,
            'recipient' => $delivery?->recipient,
            'deliveryPhone' => $delivery?->phone,
            'deliveryAddress' => $delivery?->address,
            'deliveryZone' => $delivery?->delivery_zone,
            'references' => $delivery?->references,
            'mapUrl' => $this->safeHttpUrl($delivery?->map_url),
            'total' => (float) $order->total,
            'paymentMethods' => $payment['method_summary'],
            'paymentStatus' => $payment['status'],
            'balanceDue' => $payment['balance_due'],
            'orderNotes' => $order->notes,
        ])->render();
    }

    private function commonData(Order $order, BusinessProfile $profile, string $receiptFontSize = 'small'): array
    {
        $showBusinessDetails = $profile->show_business_details !== false;
        $receiptFontSize = in_array($receiptFontSize, ['small', 'medium', 'large'], true)
            ? $receiptFontSize
            : 'small';

        return [
            'primaryColor' => $this->validColor($profile->primary_color, '#cf4b32'),
            'secondaryColor' => $this->validColor($profile->secondary_color, '#29231f'),
            'showBusinessDetails' => $showBusinessDetails,
            'logoDataUrl' => $showBusinessDetails ? $profile->logo_data_url : null,
            'businessName' => $profile->name,
            'businessPhone' => $profile->phone,
            'businessAddress' => $profile->address,
            'taxId' => $profile->tax_id,
            'socialLinks' => $this->socialLinks($profile),
            'receiptFooter' => $profile->receipt_footer,
            'orderNumber' => $order->daily_number,
            'items' => $order->items->map(fn ($item) => $this->itemData($item))->all(),
            'receiptFontSize' => $receiptFontSize,
            'receiptFontScale' => match ($receiptFontSize) {
                'large' => '120%',
                'medium' => '100%',
                default => '80%',
            },
        ];
    }

    private function itemData($item): array
    {
        $modifiers = $item->modifiers->map(fn ($modifier) => [
            'name' => (string) $modifier->name,
            'price' => (float) $modifier->price,
        ])->all();
        $components = $item->components->map(function ($component): array {
            $flavors = collect($component->flavors)->map(function ($flavor) {
                return is_array($flavor) ? ($flavor['name'] ?? null) : $flavor;
            })->filter()->map(fn ($flavor) => (string) $flavor)->values()->all();
            $modifierNames = collect($component->modifiers)->map(function ($modifier) {
                return is_array($modifier) ? ($modifier['name'] ?? null) : $modifier;
            })->filter()->map(fn ($name) => (string) $name)->values()->all();

            return [
                'quantity' => $this->displayQuantity($component->quantity),
                'name' => (string) $component->name,
                'flavors' => $flavors,
                'modifier_names' => $modifierNames,
                'notes' => $component->notes,
            ];
        })->all();

        return [
            'quantity' => $this->displayQuantity($item->quantity),
            'name' => (string) $item->name,
            'flavors' => $item->flavors->pluck('flavor.name')->filter()->map(fn ($name) => (string) $name)->values()->all(),
            'modifiers' => $modifiers,
            'modifier_names' => collect($modifiers)->pluck('name')->all(),
            'components' => $components,
            'notes' => $item->notes,
            'total' => (float) $item->total,
        ];
    }

    private function paymentData(Order $order): array
    {
        $paid = (float) $order->payments->sum('amount');
        $deliveryPaymentReceived = (bool) $order->delivery?->payment_received;
        $unsettledBalance = max(0, (float) $order->total - $paid);
        $balance = ($order->courtesy || $deliveryPaymentReceived)
            ? 0.0
            : $unsettledBalance;
        $payments = $order->payments->map(fn ($payment) => [
            'label' => $this->paymentName($payment->method),
            'amount' => (float) $payment->amount,
            'reference' => $payment->reference,
        ])->all();

        if ($order->courtesy) {
            $payments = [['label' => 'Cortesía', 'amount' => (float) $order->total, 'reference' => null]];
        }

        $methods = collect($payments)->pluck('label')->unique()->values();
        if ($order->collect_on_delivery && $unsettledBalance > 0.009) {
            $methods->push('Contra entrega');
        }
        $methodSummary = $methods->unique()->join(', ') ?: 'Pendiente';

        $status = match (true) {
            $order->courtesy => 'Cortesía',
            $deliveryPaymentReceived => 'Pagado',
            $balance <= 0.009 => 'Pagado',
            $paid > 0 => 'Pago parcial',
            $order->collect_on_delivery => 'Pendiente contra entrega',
            default => 'Pendiente',
        };
        $customerNote = match (true) {
            $order->courtesy => 'Pedido de cortesía. No requiere pago.',
            $balance <= 0.009 => null,
            $order->collect_on_delivery => 'Saldo pendiente contra entrega: $'.number_format($balance, 2),
            default => 'Saldo pendiente: $'.number_format($balance, 2),
        };

        return [
            'payments' => $payments,
            'method_summary' => $methodSummary,
            'status' => $status,
            'balance_due' => $balance,
            'customer_note' => $customerNote,
        ];
    }

    private function pdf(string $html, Order $order): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $detailCount = $order->items->sum(
            fn ($item) => 1 + $item->flavors->count() + $item->modifiers->count() + $item->components->count(),
        );
        // Thermal receipts use a custom-height page. Keep the estimate close to
        // the compact layout so the printer does not feed a large blank tail.
        $height = min(1800, max(340, 320 + $detailCount * 28));
        $pdf->setPaper([0, 0, 226.77, $height]);
        $pdf->render();
        $contents = $pdf->output();
        if (! str_starts_with($contents, '%PDF-')) {
            throw new RuntimeException('No se pudo generar un PDF válido.');
        }

        return $contents;
    }

    private function png(Order $order, BusinessProfile $profile, string $receiptFontSize = 'small'): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('La extensión GD es necesaria para generar la nota como imagen.');
        }

        $common = $this->commonData($order, $profile, $receiptFontSize);
        $payment = $this->paymentData($order);
        $lines = [];
        if ($common['showBusinessDetails']) {
            $lines[] = $profile->name;
            if ($profile->phone) {
                $lines[] = 'Tel. '.$profile->phone;
            }
            if ($profile->address) {
                $lines[] = $profile->address;
            }
            foreach ($common['socialLinks'] as $social) {
                $lines[] = $social['name'].': '.$social['value'];
            }
            $lines[] = '';
        }
        $lines[] = 'ORDEN #'.$order->daily_number;
        $lines[] = $order->created_at->format('d/m/Y H:i');
        $customerName = $order->customer?->name ?: $order->delivery?->recipient;
        if ($customerName) {
            $lines[] = 'Cliente: '.$customerName;
        }
        $customerPhone = $order->customer?->phone ?: $order->delivery?->phone;
        if ($customerPhone) {
            $lines[] = 'Telefono: '.$customerPhone;
        }
        if ($order->delivery?->address) {
            $lines[] = 'Direccion: '.$order->delivery->address;
        }
        $lines[] = '';
        foreach ($common['items'] as $item) {
            $lines[] = $item['quantity'].' x '.$item['name'].'  $'.number_format($item['total'], 2);
            if ($item['flavors']) {
                $lines[] = '  Sabores: '.implode(' / ', $item['flavors']);
            }
            if ($item['modifier_names']) {
                $lines[] = '  Extras: '.implode(', ', $item['modifier_names']);
            }
            foreach ($item['components'] as $component) {
                $lines[] = '  '.$component['quantity'].' x '.$component['name'];
                if ($component['flavors']) {
                    $lines[] = '    Sabores: '.implode(' / ', $component['flavors']);
                }
                if ($component['modifier_names']) {
                    $lines[] = '    Extras: '.implode(', ', $component['modifier_names']);
                }
            }
            if ($item['notes']) {
                $lines[] = '  Nota: '.$item['notes'];
            }
        }
        $lines[] = '';
        $lines[] = 'Subtotal: $'.number_format((float) $order->subtotal, 2);
        $lines[] = 'Descuento: -$'.number_format((float) $order->discount, 2);
        $lines[] = 'Envio: $'.number_format((float) $order->delivery_fee, 2);
        $lines[] = 'TOTAL: $'.number_format((float) $order->total, 2);
        foreach ($payment['payments'] as $row) {
            $lines[] = $row['label'].': $'.number_format($row['amount'], 2);
        }
        if ($payment['customer_note']) {
            $lines[] = $payment['customer_note'];
        }
        if ($profile->receipt_footer) {
            $lines[] = '';
            $lines[] = $profile->receipt_footer;
        }

        $wrapped = collect($lines)->flatMap(function ($line) {
            $ascii = Str::ascii((string) $line);

            return explode("\n", wordwrap($ascii, 76, "\n", true));
        })->take(250)->values();
        if ($wrapped->count() === 250) {
            $wrapped[249] = '... contenido truncado por seguridad ...';
        }

        $logo = $common['showBusinessDetails'] ? $this->logoImage($common['logoDataUrl']) : null;
        $logoHeight = $logo ? 105 : 0;
        $width = 720;
        $lineHeight = match ($common['receiptFontSize']) { 'large' => 30, 'medium' => 26, default => 22 };
        $height = max(480, 45 + $logoHeight + $wrapped->count() * $lineHeight);
        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            throw new RuntimeException('No se pudo crear la imagen de la nota.');
        }
        $white = imagecolorallocate($image, 255, 253, 249);
        $dark = imagecolorallocate($image, 41, 35, 31);
        [$red, $green, $blue] = $this->hexToRgb($common['primaryColor']);
        $brand = imagecolorallocate($image, $red, $green, $blue);
        imagefilledrectangle($image, 0, 0, $width, $height, $white);
        imagefilledrectangle($image, 0, 0, 9, $height, $brand);

        $y = 24;
        if ($logo) {
            [$logoImage, $logoWidth, $sourceHeight] = $logo;
            $scale = min(180 / $logoWidth, 90 / $sourceHeight, 1);
            $targetWidth = max(1, (int) round($logoWidth * $scale));
            $targetHeight = max(1, (int) round($sourceHeight * $scale));
            imagecopyresampled($image, $logoImage, 28, $y, 0, 0, $targetWidth, $targetHeight, $logoWidth, $sourceHeight);
            imagedestroy($logoImage);
            $y += $logoHeight;
        }
        foreach ($wrapped as $index => $line) {
            $normalFont = match ($common['receiptFontSize']) { 'large' => 5, 'medium' => 4, default => 3 };
            $font = $index === 0 || str_starts_with($line, 'ORDEN #') || str_starts_with($line, 'TOTAL:') ? min(5, $normalFont + 1) : $normalFont;
            $color = str_starts_with($line, 'TOTAL:') ? $brand : $dark;
            imagestring($image, $font, 28, $y + $index * $lineHeight, $line, $color);
        }

        ob_start();
        $written = imagepng($image);
        $contents = (string) ob_get_clean();
        imagedestroy($image);
        if (! $written || ! str_starts_with($contents, "\x89PNG\r\n\x1a\n")) {
            throw new RuntimeException('No se pudo generar un PNG válido.');
        }

        return $contents;
    }

    private function logoImage(?string $dataUrl): ?array
    {
        if (! $dataUrl || ! preg_match('#^data:image/(?:png|jpeg);base64,(.+)$#sD', $dataUrl, $matches)) {
            return null;
        }
        $contents = base64_decode($matches[1], true);
        $image = $contents === false ? false : @imagecreatefromstring($contents);
        if ($image === false) {
            return null;
        }

        return [$image, imagesx($image), imagesy($image)];
    }

    private function loadOrder(Order $order): void
    {
        $order->loadMissing([
            'items.flavors.flavor',
            'items.modifiers',
            'items.components',
            'payments',
            'delivery',
            'customer',
        ]);
    }

    private function socialLinks(BusinessProfile $profile): array
    {
        return collect($profile->social_links)
            ->filter(fn ($link) => is_array($link) && is_string($link['name'] ?? null) && is_string($link['value'] ?? null))
            ->map(fn ($link) => ['name' => trim($link['name']), 'value' => trim($link['value'])])
            ->filter(fn ($link) => $link['name'] !== '' && $link['value'] !== '')
            ->take(10)
            ->values()
            ->all();
    }

    private function validColor(?string $color, string $fallback): string
    {
        return is_string($color) && preg_match('/^#[0-9A-Fa-f]{6}$/D', $color) ? strtolower($color) : $fallback;
    }

    private function hexToRgb(string $color): array
    {
        $hex = ltrim($color, '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    private function displayQuantity(mixed $quantity): string
    {
        return rtrim(rtrim(number_format((float) $quantity, 2, '.', ''), '0'), '.');
    }

    private function safeHttpUrl(?string $url): ?string
    {
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }

    private function paymentName(string $method): string
    {
        return match ($method) {
            'cash' => 'Efectivo',
            'transfer' => 'Transferencia',
            'courtesy' => 'Cortesía',
            default => $method,
        };
    }

    private function orderType(string $type): string
    {
        return match ($type) {
            'pickup' => 'Recoger',
            'delivery' => 'Domicilio',
            'dine_in' => 'Local',
            'whatsapp' => 'WhatsApp',
            default => $type,
        };
    }
}
