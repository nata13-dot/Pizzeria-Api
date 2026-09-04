@extends('documents.layout')

@section('content')
@php($receiptFontPixels = ['small' => 8, 'medium' => 10, 'large' => 12][$receiptFontSize ?? 'small'])
<section class="print-document customer-ticket" data-print-font-size="{{ $receiptFontSize ?? 'small' }}" style="--receipt-font-size: {{ $receiptFontPixels }}px">
    @include('documents._business', ['compactBusiness' => true])

    <div class="ticket-heading ticket-title">
        <h2>NOTA DE VENTA</h2>
        <div class="folio">#{{ str_pad((string) $orderNumber, 4, '0', STR_PAD_LEFT) }}</div>
    </div>
    <div class="ticket-meta"><span>{{ $createdDate }}</span><span>{{ $createdTime }}</span></div>

    <h3>Cliente</h3>
    <div class="customer-card">
        <strong>{{ $customerName ?: 'Público general' }}</strong>
        @if ($customerPhone)<div>Tel. {{ $customerPhone }}</div>@endif
        @if ($recipient && $recipient !== $customerName)<div>Recibe: {{ $recipient }}</div>@endif
        @if ($deliveryAddress)<div>Dirección: {{ $deliveryAddress }}</div>@endif
    </div>

    <h3>Pedido</h3>
    <table>
        <tbody>
        @foreach ($items as $item)
            <tr>
                <td>
                    <strong>{{ $item['quantity'] }}x {{ $item['name'] }}</strong>
                    @if ($item['flavors'])<div class="item-detail">• {{ implode(' / ', $item['flavors']) }}</div>@endif
                    @foreach ($item['modifiers'] as $modifier)
                        <div class="item-detail">• {{ $modifier['name'] }}@if ($modifier['price'] > 0) (+${{ number_format($modifier['price'], 2) }})@endif</div>
                    @endforeach
                    @foreach ($item['components'] as $component)
                        <div class="item-detail">
                            • {{ $component['quantity'] }}x {{ $component['name'] }}
                            @if ($component['flavors']) · {{ implode(' / ', $component['flavors']) }}@endif
                            @if ($component['modifier_names']) · Extras: {{ implode(', ', $component['modifier_names']) }}@endif
                            @if ($component['notes']) · {{ $component['notes'] }}@endif
                        </div>
                    @endforeach
                    @if ($item['notes'])<div class="item-detail">Nota: {{ $item['notes'] }}</div>@endif
                </td>
                <td>${{ number_format($item['total'], 2) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="summary">
        <div class="summary-row"><span>Subtotal</span><span>${{ number_format($subtotal, 2) }}</span></div>
        <div class="summary-row"><span>Descuento</span><span>${{ number_format($discount, 2) }}</span></div>
        <div class="summary-row"><span>Envío</span><span>${{ number_format($deliveryFee, 2) }}</span></div>
        <div class="summary-row total"><span>Total</span><span>${{ number_format($total, 2) }}</span></div>
    </div>

    <h3>Pago</h3>
    <div class="payment-list">@foreach ($payments as $payment)
        <div><strong>{{ $payment['label'] }}</strong>@if ($payment['reference']) · Ref. {{ $payment['reference'] }}@endif <span style="float:right">${{ number_format($payment['amount'], 2) }}</span></div>
    @endforeach</div>
    @if ($paymentNote)<div class="status">{{ $paymentNote }}</div>@endif

    <div class="message">
        <strong>{{ $receiptFooter ?: '¡GRACIAS POR TU COMPRA!' }}</strong>
        <div class="footer-mark">&#127829; &nbsp; &#128293; &nbsp; &#127829;</div>
        @if ($showBusinessDetails)<div>{{ $businessName }}</div>@endif
    </div>
</section>
@endsection
