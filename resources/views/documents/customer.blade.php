@extends('documents.layout')

@section('content')
    @include('documents._business')

    <div class="ticket-heading">
        <div><h2>Nota de venta</h2><div class="muted">Comprobante de pedido</div></div>
        <div class="folio">Folio #{{ $orderNumber }}</div>
    </div>
    <div class="ticket-meta">Fecha: {{ $createdDate }} &nbsp;·&nbsp; Hora: {{ $createdTime }}</div>

    <h3>Datos del cliente</h3>
    <div class="customer-card">
        <strong>{{ $customerName ?: 'Público general' }}</strong>
        @if ($customerPhone)<div>Teléfono: {{ $customerPhone }}</div>@endif
        @if ($recipient && $recipient !== $customerName)<div>Recibe: {{ $recipient }}</div>@endif
        @if ($deliveryAddress)<div>Dirección: {{ $deliveryAddress }}</div>@endif
    </div>

    <h3>Detalle del pedido</h3>
    <table>
        <thead><tr><th>Productos y extras</th><th>Importe</th></tr></thead>
        <tbody>
        @foreach ($items as $item)
            <tr>
                <td>
                    <strong>{{ $item['quantity'] }} × {{ $item['name'] }}</strong>
                    @if ($item['flavors'])<div class="item-detail">Sabores: {{ implode(' / ', $item['flavors']) }}</div>@endif
                    @foreach ($item['modifiers'] as $modifier)
                        <div class="item-detail">Extra: {{ $modifier['name'] }}@if ($modifier['price'] > 0) (+${{ number_format($modifier['price'], 2) }})@endif</div>
                    @endforeach
                    @foreach ($item['components'] as $component)
                        <div class="item-detail">
                            Componente: {{ $component['quantity'] }} × {{ $component['name'] }}
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
        <div class="summary-row"><span>Descuento</span><span>-${{ number_format($discount, 2) }}</span></div>
        <div class="summary-row"><span>Envío</span><span>${{ number_format($deliveryFee, 2) }}</span></div>
        <div class="summary-row total"><span>Total</span><span>${{ number_format($total, 2) }}</span></div>
    </div>

    <h3>Forma de pago</h3>
    <div class="payment-list">@foreach ($payments as $payment)
        <div><strong>{{ $payment['label'] }}</strong>@if ($payment['reference']) · Ref. {{ $payment['reference'] }}@endif <span style="float:right">${{ number_format($payment['amount'], 2) }}</span></div>
    @endforeach</div>
    @if ($paymentNote)<div class="status">{{ $paymentNote }}</div>@endif

    @if ($receiptFooter)<p class="message">{{ $receiptFooter }}</p>@endif
@endsection
