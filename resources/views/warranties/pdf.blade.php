<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #111; font-size: 12px; }
        .box { border: 2px solid #111; padding: 24px; }
        h1 { font-size: 20px; margin: 0 0 8px; }
        .muted { color: #555; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border: 1px solid #333; padding: 8px; text-align: right; }
        th { background: #f2f2f2; }
        .footer { margin-top: 24px; font-size: 11px; border-top: 1px solid #999; padding-top: 12px; }
        .code { font-size: 18px; font-weight: bold; letter-spacing: 1px; }
    </style>
</head>
<body>
<div class="box">
    <h1>MG للصناعات البلاستيكية</h1>
    <p class="muted">شهادة ضمان رسمية</p>
    <p class="code">{{ $warranty->code }}</p>
    <p>السباك: <strong>{{ $warranty->plumber?->name }}</strong></p>
    <p>صاحب العمل: <strong>{{ $warranty->customer_name }}</strong> ({{ $warranty->maskedPhone() }})</p>
    <p>تاريخ الإصدار: {{ optional($warranty->issued_at)->format('Y-m-d') }}</p>
    <p>ساري حتى: {{ optional($warranty->expires_at)->format('Y-m-d') }}</p>
    <p>التحقق: {{ $verifyUrl }}</p>

    <table>
        <thead>
        <tr><th>المنتج</th><th>الكمية</th><th>مدة الضمان (شهر)</th></tr>
        </thead>
        <tbody>
        @foreach($warranty->items as $item)
            <tr>
                <td>{{ $item->product ? localized_name($item->product, 'name', 'منتج') : 'منتج' }}</td>
                <td>{{ $item->quantity }}</td>
                <td>{{ $item->warranty_months }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="footer">
        <p><strong>الشروط:</strong> {{ $terms }}</p>
        <p>توقيع نصي: MG للصناعات البلاستيكية</p>
    </div>
</div>
</body>
</html>
