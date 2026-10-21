<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>فاتورة {{ $invoice->number ?? $invoice->id }}</title>
    <style>
        @page { margin: 24px; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            color: #111;
            direction: rtl;
        }
        .header {
            background: #1a56db;
            color: #fff;
            padding: 16px 20px;
            margin-bottom: 16px;
        }
        .brand { font-size: 18px; font-weight: bold; }
        .meta { margin: 12px 0 16px; }
        .meta td { padding: 4px 8px; vertical-align: top; }
        .meta .label { color: #666; font-size: 10px; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.items th {
            background: #eff6ff;
            color: #1e40af;
            padding: 8px 6px;
            border: 1px solid #bfdbfe;
            font-size: 11px;
        }
        table.items td {
            padding: 7px 6px;
            border: 1px solid #e5e7eb;
            text-align: center;
        }
        table.items td.name { text-align: right; }
        .totals {
            margin-top: 16px;
            width: 45%;
            float: left;
            border: 1px solid #bbf7d0;
            background: #f0fdf4;
            padding: 10px 12px;
        }
        .totals .row { display: table; width: 100%; margin: 4px 0; }
        .totals .row span { display: table-cell; }
        .totals .row span:last-child { text-align: left; font-weight: bold; }
        .clear { clear: both; }
        .footer { margin-top: 28px; font-size: 10px; color: #666; }
    </style>
</head>
<body>
@php
    $items = $invoice->items;
    $totalQty = (int) $items->sum('quantity');
    $totalPoints = (int) ($invoice->points_awarded ?: $items->sum('total_points'));
    $party = $invoice->counterparty
        ?? $invoice->plumber
        ?? $invoice->wholesaleDistributor;
@endphp

<div class="header">
    <div class="brand">MG Plastic</div>
    <div>فاتورة {{ $invoice->number ?: ('#'.$invoice->id) }}</div>
    @if($invoice->serial_number)
        <div style="font-size:11px;opacity:.9">مسلسل: {{ $invoice->serial_number }}</div>
    @endif
</div>

<table class="meta" width="100%">
    <tr>
        <td width="33%">
            <div class="label">التاريخ</div>
            <div>{{ optional($invoice->created_at)->format('Y-m-d H:i') }}</div>
        </td>
        <td width="33%">
            <div class="label">الحالة</div>
            <div>{{ $invoice->status }}</div>
        </td>
        <td width="33%">
            <div class="label">المستلم / الطرف</div>
            <div>{{ $party?->name ?: '—' }}</div>
            <div style="font-size:10px;color:#666">{{ $party?->phone }}</div>
        </td>
    </tr>
    <tr>
        <td>
            <div class="label">مصدر الفاتورة</div>
            <div>{{ $invoice->wholesaleDistributor?->name ?: ($invoice->issuer?->name ?: '—') }}</div>
        </td>
        <td>
            <div class="label">النوع</div>
            <div>{{ $invoice->invoice_type }} / {{ $invoice->invoice_flow }}</div>
        </td>
        <td>
            <div class="label">النقاط المعتمدة</div>
            <div>{{ number_format($totalPoints) }}</div>
        </td>
    </tr>
</table>

<table class="items">
    <thead>
        <tr>
            <th>#</th>
            <th class="name">المنتج</th>
            <th>الكمية</th>
            <th>صناديق</th>
            <th>نقطة/وحدة</th>
            <th>المجموع</th>
        </tr>
    </thead>
    <tbody>
        @foreach($items as $i => $item)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td class="name">{{ localized_name($item->product, 'name', 'منتج #'.$item->product_id) }}</td>
                <td>{{ (int) $item->quantity }}</td>
                <td>{{ $item->box_count ?: '—' }}</td>
                <td>{{ rtrim(rtrim(number_format((float) $item->points_per_unit, 4, '.', ''), '0'), '.') }}</td>
                <td>{{ (int) $item->total_points }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<div class="totals">
    <div class="row"><span>إجمالي الكمية</span><span>{{ number_format($totalQty) }}</span></div>
    <div class="row"><span>إجمالي النقاط</span><span>{{ number_format($totalPoints) }}</span></div>
</div>
<div class="clear"></div>

<div class="footer">
    وثيقة صادرة من نظام MG Plastic — {{ now()->format('Y-m-d H:i') }}
</div>
</body>
</html>
