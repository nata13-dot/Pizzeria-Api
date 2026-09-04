@if ($showBusinessDetails)
    <header class="business">
        @if ($logoDataUrl)
            <img src="{{ $logoDataUrl }}" alt="Logo de {{ $businessName }}">
        @endif
        <h1>{{ $businessName }}</h1>
        @if ($businessPhone)
            <div class="business-detail">Tel. {{ $businessPhone }}</div>
        @endif
        @if ($businessAddress)
            <div class="business-detail">{{ $businessAddress }}</div>
        @endif
        @if ($taxId && empty($compactBusiness))
            <div class="business-detail">RFC / identificación fiscal: {{ $taxId }}</div>
        @endif
        @if ($socialLinks && empty($compactBusiness))
            <div class="business-detail">
                @foreach ($socialLinks as $social)
                    <span>{{ $social['name'] }}: {{ $social['value'] }}@if (! $loop->last) · @endif</span>
                @endforeach
            </div>
        @endif
    </header>
@endif
