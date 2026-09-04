@extends('documents.layout')

@section('content')
@php($receiptFontPixels = ['small' => 8, 'medium' => 10, 'large' => 12][$receiptFontSize ?? 'small'])
<section class="print-document" data-print-font-size="{{ $receiptFontSize ?? 'small' }}" style="--receipt-font-size: {{ $receiptFontPixels }}px">
    @include('documents._business')

    <h1>Comanda de cocina</h1>
    <h2>Orden #{{ $orderNumber }}</h2>
    <div class="grid">
        <div><strong>Hora:</strong> {{ $createdTime }}</div>
        <div><strong>Tipo:</strong> {{ $orderType }}</div>
    </div>
    <div><strong>Prioridad:</strong> {{ $priority }}</div>
    @if ($scheduledAt)<div><strong>Programada:</strong> {{ $scheduledAt }}</div>@endif
    @if ($orderNotes)<div class="status"><strong>Nota general:</strong> {{ $orderNotes }}</div>@endif

    <table>
        <thead><tr><th>Preparación</th>@if ($showPrices)<th>Importe</th>@endif</tr></thead>
        <tbody>
        @foreach ($items as $item)
            <tr>
                <td>
                    <strong>{{ $item['quantity'] }} × {{ $item['name'] }}</strong>
                    @if ($item['flavors'])<div class="item-detail">Sabores: {{ implode(' / ', $item['flavors']) }}</div>@endif
                    @if ($item['modifier_names'])<div class="item-detail">Extras: {{ implode(', ', $item['modifier_names']) }}</div>@endif
                    @foreach ($item['components'] as $component)
                        <div class="item-detail">
                            {{ $component['quantity'] }} × {{ $component['name'] }}
                            @if ($component['flavors']) · {{ implode(' / ', $component['flavors']) }}@endif
                            @if ($component['modifier_names']) · Extras: {{ implode(', ', $component['modifier_names']) }}@endif
                            @if ($component['notes']) · Nota: {{ $component['notes'] }}@endif
                        </div>
                    @endforeach
                    @if ($item['notes'])<div class="item-detail"><strong>Preparación:</strong> {{ $item['notes'] }}</div>@endif
                </td>
                @if ($showPrices)<td>${{ number_format($item['total'], 2) }}</td>@endif
            </tr>
        @endforeach
        </tbody>
    </table>
    @if ($showPrices)<div class="total">Total: ${{ number_format($total, 2) }}</div>@endif
</section>
@endsection
