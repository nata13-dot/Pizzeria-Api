<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $documentTitle }}</title>
    <style>
        * { box-sizing: border-box; }
        body { color: {{ $secondaryColor }}; font: 14px Arial, sans-serif; margin: 0; padding: 18px; }
        h1, h2, h3, p { margin-top: 0; }
        h1 { color: {{ $primaryColor }}; font-size: 24px; margin-bottom: 6px; }
        h2 { font-size: 19px; margin: 14px 0 8px; }
        h3 { font-size: 15px; margin: 12px 0 6px; }
        table { border-collapse: collapse; margin: 14px 0; width: 100%; }
        th, td { border-bottom: 1px solid #ded9d4; padding: 7px 3px; text-align: left; vertical-align: top; }
        th:last-child, td:last-child { text-align: right; }
        .business { border-bottom: 2px solid {{ $primaryColor }}; margin-bottom: 14px; padding-bottom: 12px; text-align: center; }
        .business img { display: block; margin: 0 auto 8px; max-height: 90px; max-width: 170px; }
        .business-detail, .muted { color: #6f6862; font-size: 12px; }
        .grid { display: table; width: 100%; }
        .grid > div { display: table-cell; padding-right: 8px; vertical-align: top; width: 50%; }
        .item-detail { color: #5f5751; font-size: 12px; margin-top: 3px; }
        .summary { margin-left: auto; width: 70%; }
        .summary-row { display: flex; justify-content: space-between; padding: 3px 0; }
        .total { border-top: 2px solid {{ $primaryColor }}; font-size: 20px; font-weight: bold; margin-top: 8px; padding-top: 8px; }
        .status { border: 1px solid {{ $primaryColor }}; border-radius: 5px; margin: 8px 0; padding: 8px; }
        .message { border-top: 1px dashed #aaa; margin-top: 18px; padding-top: 12px; text-align: center; }
        .print-button { background: {{ $primaryColor }}; border: 0; border-radius: 5px; color: #fff; cursor: pointer; margin-top: 18px; padding: 10px 16px; }
        a { color: {{ $primaryColor }}; overflow-wrap: anywhere; }
        @media print { .print-button { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
    @yield('content')
    <button class="print-button" type="button" onclick="window.print()">Imprimir</button>
</body>
</html>
