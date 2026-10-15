<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>طلب {{ $order->order_number }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Cairo', sans-serif;
            background: linear-gradient(165deg, #ecfdf5 0%, #f0f9ff 45%, #f8fafc 100%);
            color: #0f172a;
            min-height: 100vh;
            line-height: 1.7;
        }
        .wrap { max-width: 640px; margin: 0 auto; padding: 1.5rem 1rem 3rem; }
        .brand {
            display: flex; align-items: center; gap: 0.75rem;
            margin-bottom: 1.25rem;
        }
        .brand strong { font-size: 1.15rem; font-weight: 800; color: #0f766e; }
        .card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 1.25rem;
            padding: 1.35rem 1.25rem;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.06);
        }
        h1 { font-size: 1.35rem; font-weight: 800; margin-bottom: 0.35rem; }
        .muted { color: #64748b; font-size: 0.92rem; margin-bottom: 1rem; }
        .status {
            display: inline-block;
            background: #ecfdf5;
            color: #047857;
            font-weight: 800;
            font-size: 0.85rem;
            padding: 0.35rem 0.75rem;
            border-radius: 999px;
            margin-bottom: 1rem;
        }
        .meta { display: grid; gap: 0.55rem; margin-bottom: 1.25rem; }
        .meta div {
            display: flex; justify-content: space-between; gap: 1rem;
            border-bottom: 1px dashed #e2e8f0; padding-bottom: 0.45rem;
            font-size: 0.95rem;
        }
        .meta span { color: #64748b; }
        .meta strong { font-weight: 800; }
        table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        th, td { text-align: right; padding: 0.55rem 0.25rem; border-bottom: 1px solid #f1f5f9; }
        th { color: #64748b; font-weight: 700; font-size: 0.8rem; }
        .foot { margin-top: 1.25rem; color: #64748b; font-size: 0.85rem; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="brand">
        <strong>{{ $settings->site_name ?: 'MG Plastic' }}</strong>
    </div>
    <div class="card">
        <h1>طلب {{ $order->order_number }}</h1>
        <p class="muted">{{ $statusDescription }}</p>
        <div class="status">{{ $statusLabel }}</div>
        <div class="meta">
            <div><span>الطالب</span><strong>{{ $order->requester?->name ?: '—' }}</strong></div>
            <div><span>المورّد</span><strong>{{ $order->supplier?->name ?: '—' }}</strong></div>
            <div><span>الكمية</span><strong>{{ number_format((int) $order->total_quantity) }} وحدة</strong></div>
            <div><span>النقاط</span><strong>{{ number_format((int) $order->total_points) }}</strong></div>
            @if ($order->placed_at)
                <div><span>تاريخ الطلب</span><strong>{{ $order->placed_at->format('Y-m-d H:i') }}</strong></div>
            @endif
        </div>
        @if ($order->items->isNotEmpty())
            <table>
                <thead>
                <tr>
                    <th>المنتج</th>
                    <th>الكمية</th>
                    <th>النقاط</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($order->items as $item)
                    <tr>
                        <td>{{ $item->name_snapshot ?: ($item->product?->translate('ar')?->name ?: ('#'.$item->product_id)) }}</td>
                        <td>{{ (int) $item->quantity }}</td>
                        <td>{{ (int) $item->line_points }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
        <p class="foot">رابط آمن من MG Plastic — لا تحتاج تسجيل دخول لعرض هذا الطلب.</p>
    </div>
</div>
</body>
</html>
