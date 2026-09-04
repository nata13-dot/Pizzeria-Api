<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $documentTitle }}</title>
    <style>
        * { box-sizing: border-box; }
        :root { --brand: {{ $primaryColor }}; --ink: {{ $secondaryColor }}; --muted: #706963; --line: #e4ded8; --paper: #fffdfa; }
        body { background: #f2efeb; color: var(--ink); font: 14px Arial, sans-serif; margin: 0; padding: 24px; }
        body > .document { background: var(--paper); border: 1px solid var(--line); border-radius: 14px; box-shadow: 0 10px 30px rgba(44, 35, 29, .08); margin: 0 auto; max-width: 720px; overflow: hidden; padding: 24px; }
        h1, h2, h3, p { margin-top: 0; }
        h1 { color: {{ $primaryColor }}; font-size: 24px; margin-bottom: 6px; }
        h2 { font-size: 19px; margin: 0; }
        h3 { color: var(--muted); font-size: 11px; letter-spacing: 1.2px; margin: 18px 0 7px; text-transform: uppercase; }
        table { border-collapse: collapse; margin: 14px 0; width: 100%; }
        th, td { border-bottom: 1px solid var(--line); padding: 10px 4px; text-align: left; vertical-align: top; }
        th { color: var(--muted); font-size: 11px; letter-spacing: .7px; text-transform: uppercase; }
        th:last-child, td:last-child { text-align: right; }
        .business { border-bottom: 2px solid var(--brand); margin-bottom: 18px; padding-bottom: 16px; text-align: center; }
        .business img { display: block; margin: 0 auto 8px; max-height: 90px; max-width: 170px; }
        .business-detail, .muted { color: var(--muted); font-size: 12px; }
        .ticket-heading { align-items: center; display: flex; gap: 12px; justify-content: space-between; }
        .folio { background: var(--brand); border-radius: 999px; color: #fff; font-size: 13px; font-weight: bold; padding: 7px 12px; white-space: nowrap; }
        .ticket-meta { border-bottom: 1px dashed #bdb5ae; color: var(--muted); font-size: 12px; margin-top: 8px; padding-bottom: 14px; }
        .customer-card { background: #f7f3ef; border-left: 4px solid var(--brand); border-radius: 8px; line-height: 1.55; padding: 11px 13px; }
        .grid { display: table; width: 100%; }
        .grid > div { display: table-cell; padding-right: 8px; vertical-align: top; width: 50%; }
        .item-detail { color: #5f5751; font-size: 12px; margin-top: 3px; }
        .summary { background: #f7f3ef; border-radius: 10px; margin-left: auto; padding: 12px 14px; width: min(100%, 330px); }
        .summary-row { display: flex; justify-content: space-between; padding: 4px 0; }
        .total { border-top: 2px solid var(--brand); color: var(--brand); font-size: 21px; font-weight: bold; margin-top: 8px; padding-top: 10px; }
        .payment-list { line-height: 1.7; }
        .status { border: 1px solid {{ $primaryColor }}; border-radius: 5px; margin: 8px 0; padding: 8px; }
        .message { border-top: 1px dashed #aaa; font-weight: bold; margin-top: 20px; padding-top: 15px; text-align: center; }
        .print-button { background: {{ $primaryColor }}; border: 0; border-radius: 5px; color: #fff; cursor: pointer; margin-top: 18px; padding: 10px 16px; }
        .customer-ticket { font-size: 12px; line-height: 1.3; }
        .customer-ticket .business { border-bottom: 1px dashed #222; margin-bottom: 10px; padding-bottom: 9px; }
        .customer-ticket .business img { margin-bottom: 4px; max-height: 52px; max-width: 120px; }
        .customer-ticket .business h1 { font-size: 18px; margin-bottom: 2px; text-transform: uppercase; }
        .customer-ticket .business-detail { color: inherit; font-size: 11px; line-height: 1.25; }
        .customer-ticket .ticket-title { display: block; text-align: center; }
        .customer-ticket .ticket-title h2 { font-size: 15px; letter-spacing: 1px; }
        .customer-ticket .folio { background: none; color: inherit; font-size: 14px; padding: 1px 0; }
        .customer-ticket .ticket-meta { display: flex; justify-content: space-between; margin-top: 6px; padding: 6px 0; }
        .customer-ticket h3 { border-bottom: 1px solid #222; color: inherit; font-size: 11px; letter-spacing: .7px; margin: 8px 0 4px; padding-bottom: 3px; }
        .customer-ticket .customer-card { background: none; border: 0; border-radius: 0; line-height: 1.3; padding: 0; }
        .customer-ticket table { margin: 0 0 6px; }
        .customer-ticket td { border-bottom: 1px dashed #777; padding: 5px 0; }
        .customer-ticket td:last-child { padding-left: 5px; white-space: nowrap; width: 25%; }
        .customer-ticket .item-detail { color: inherit; font-size: 11px; margin: 1px 0 0 8px; }
        .customer-ticket .summary { background: none; border-radius: 0; border-bottom: 1px solid #222; border-top: 1px solid #222; padding: 4px 0; width: 100%; }
        .customer-ticket .summary-row { padding: 1px 0; }
        .customer-ticket .total { border-top: 1px dashed #777; color: inherit; font-size: 16px; margin-top: 3px; padding-top: 4px; }
        .customer-ticket .payment-list { line-height: 1.35; }
        .customer-ticket .status { margin: 5px 0; padding: 4px; }
        .customer-ticket .message { border-top: 1px dashed #222; font-weight: normal; line-height: 1.45; margin-top: 10px; padding-top: 8px; text-align: center; }
        .customer-ticket .footer-mark { font-size: 15px; }
        a { color: {{ $primaryColor }}; overflow-wrap: anywhere; }
        @media (max-width: 520px) { body { padding: 0; } body > .document { border: 0; border-radius: 0; box-shadow: none; padding: 16px; } .ticket-heading { align-items: flex-start; } }
        @media print { .print-button { display: none; } body { background: #fff; padding: 0; } body > .document { border: 0; border-radius: 0; box-shadow: none; max-width: none; padding: 0; } .customer-ticket { break-after: avoid; break-inside: avoid; } }
    </style>
</head>
<body>
    <main class="document">
        @yield('content')
        <button class="print-button" type="button" onclick="window.print()">Imprimir</button>
    </main>
</body>
</html>
