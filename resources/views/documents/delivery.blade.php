@extends('documents.layout')

@section('content')
    @include('documents._business')

    <h1>Hoja de reparto</h1>
    <h2>Orden #{{ $orderNumber }}</h2>
    <div><strong>Cliente:</strong> {{ $customerName ?: $recipient }}</div>
    @if ($recipient)<div><strong>Recibe:</strong> {{ $recipient }}</div>@endif
    <div><strong>Teléfono:</strong> {{ $deliveryPhone ?: $customerPhone }}</div>
    <div><strong>Dirección:</strong> {{ $deliveryAddress }}</div>
    @if ($deliveryZone)<div><strong>Zona:</strong> {{ $deliveryZone }}</div>@endif
    @if ($references)<div><strong>Referencias:</strong> {{ $references }}</div>@endif
    @if ($mapUrl)<div><strong>Mapa:</strong> <a href="{{ $mapUrl }}">{{ $mapUrl }}</a></div>@endif

    <h3>Productos</h3>
    <ul>
    @foreach ($items as $item)
        <li>
            {{ $item['quantity'] }} × {{ $item['name'] }}
            @if ($item['flavors']) · {{ implode(' / ', $item['flavors']) }}@endif
            @if ($item['modifier_names']) · Extras: {{ implode(', ', $item['modifier_names']) }}@endif
        </li>
    @endforeach
    </ul>

    <div><strong>Total del pedido:</strong> ${{ number_format($total, 2) }}</div>
    <div><strong>Método de pago:</strong> {{ $paymentMethods }}</div>
    <div><strong>Estado de pago:</strong> {{ $paymentStatus }}</div>
    <div class="total">Saldo por cobrar: ${{ number_format($balanceDue, 2) }}</div>
    @if ($orderNotes)<div class="status"><strong>Notas de reparto:</strong> {{ $orderNotes }}</div>@endif
@endsection
