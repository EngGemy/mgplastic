<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إيصال تحويل #{{ $withdrawal->id }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Cairo', sans-serif;
            background: #eef2ff;
            color: #0f172a;
            line-height: 1.6;
            min-height: 100vh;
        }
        .toolbar {
            background: linear-gradient(135deg, #1d4ed8, #0f766e);
            color: #fff;
            padding: 0.85rem 1.25rem;
            display: flex;
            gap: 0.65rem;
            align-items: center;
            flex-wrap: wrap;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .toolbar span { flex: 1; font-weight: 700; font-size: 0.95rem; }
        .toolbar button, .toolbar a {
            background: #fff;
            color: #1d4ed8;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 999px;
            font-family: inherit;
            font-weight: 800;
            font-size: 0.85rem;
            cursor: pointer;
            text-decoration: none;
        }
        .wrap { max-width: 520px; margin: 1.25rem auto; padding: 0 1rem 2rem; }
        .card {
            background: #fff;
            border-radius: 22px;
            overflow: hidden;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.12);
        }
        .hero {
            background: linear-gradient(145deg, #0f766e 0%, #1d4ed8 100%);
            color: #fff;
            padding: 1.6rem 1.4rem 1.4rem;
            text-align: center;
            position: relative;
        }
        .hero::after {
            content: '';
            position: absolute;
            inset-inline: 0;
            bottom: -1px;
            height: 18px;
            background: radial-gradient(circle at 10px 0, transparent 10px, #fff 11px) repeat-x;
            background-size: 20px 18px;
        }
        .stamp {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            background: rgba(255,255,255,0.18);
            border: 1px solid rgba(255,255,255,0.35);
            padding: 0.35rem 0.85rem;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 800;
            margin-bottom: 0.85rem;
        }
        .hero h1 { font-size: 1.35rem; font-weight: 900; margin-bottom: 0.25rem; }
        .hero p { opacity: 0.9; font-size: 0.9rem; }
        .amount-box {
            margin-top: 1.1rem;
            background: rgba(255,255,255,0.14);
            border-radius: 16px;
            padding: 0.9rem 1rem;
        }
        .amount-box .label { font-size: 0.78rem; opacity: 0.85; }
        .amount-box .value { font-size: 2rem; font-weight: 900; letter-spacing: -0.02em; }
        .body { padding: 1.35rem 1.25rem 1.5rem; }
        .row {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            padding: 0.7rem 0;
            border-bottom: 1px dashed #e2e8f0;
            font-size: 0.9rem;
        }
        .row:last-child { border-bottom: none; }
        .row .k { color: #64748b; font-weight: 600; }
        .row .v { font-weight: 800; text-align: left; color: #0f172a; }
        .proof {
            margin-top: 1rem;
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 14px;
            padding: 0.9rem 1rem;
        }
        .proof h3 { color: #166534; font-size: 0.85rem; margin-bottom: 0.45rem; }
        .proof .chip {
            display: inline-block;
            background: #fff;
            border: 1px solid #86efac;
            color: #14532d;
            border-radius: 8px;
            padding: 0.25rem 0.55rem;
            font-size: 0.78rem;
            font-weight: 800;
            margin: 0.15rem 0.15rem 0 0;
        }
        .footer {
            margin-top: 1.2rem;
            text-align: center;
            color: #94a3b8;
            font-size: 0.75rem;
        }
        .brand { font-weight: 900; color: #1d4ed8; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none !important; }
            .wrap { margin: 0; max-width: none; padding: 0; }
            .card { box-shadow: none; border-radius: 0; }
        }
    </style>
</head>
<body>
@if(($mode ?? 'view') !== 'embed')
<div class="toolbar">
    <span>إيصال تحويل مستحقات — MG Plastic</span>
    <button type="button" onclick="window.print()">طباعة / حفظ PDF</button>
</div>
@endif

<div class="wrap">
    <div class="card">
        <div class="hero">
            <div class="stamp">✓ تم التحويل بنجاح</div>
            <h1>إيصال صرف مستحقات</h1>
            <p>طلب سحب رقم #{{ $withdrawal->id }}</p>
            <div class="amount-box">
                <div class="label">المبلغ المحوَّل</div>
                <div class="value">{{ $withdrawal->formattedAmount() }}</div>
            </div>
        </div>

        <div class="body">
            <div class="row">
                <span class="k">المستفيد</span>
                <span class="v">{{ $withdrawal->plumber?->name ?? '—' }}</span>
            </div>
            <div class="row">
                <span class="k">الهاتف</span>
                <span class="v">{{ $withdrawal->plumber?->phone ?? '—' }}</span>
            </div>
            <div class="row">
                <span class="k">طريقة الصرف</span>
                <span class="v">{{ $withdrawal->methodLabel() }}</span>
            </div>
            <div class="row">
                <span class="k">بيانات الاستلام</span>
                <span class="v">{{ $withdrawal->payoutDetailsSummary() }}</span>
            </div>
            <div class="row">
                <span class="k">تاريخ التحويل</span>
                <span class="v">{{ optional($withdrawal->paid_at)->timezone('Africa/Tripoli')->format('Y/m/d — h:i A') ?? '—' }}</span>
            </div>
            <div class="row">
                <span class="k">الحالة</span>
                <span class="v" style="color:#059669;">{{ $withdrawal->statusLabel() }}</span>
            </div>

            @if($withdrawal->paymentProofSummary())
                <div class="proof">
                    <h3>مرجع التحويل / الإيصال</h3>
                    @if(filled($withdrawal->receipt_number))
                        <span class="chip">إيصال: {{ $withdrawal->receipt_number }}</span>
                    @endif
                    @if(filled($withdrawal->transfer_number))
                        <span class="chip">تحويل: {{ $withdrawal->transfer_number }}</span>
                    @endif
                </div>
            @endif

            <div class="footer">
                <div class="brand">MG Plastic</div>
                <div>هذا إيصال رسمي لعملية تحويل مستحقات النقاط.</div>
                <div>احتفظ به للمراجعة — {{ now()->timezone('Africa/Tripoli')->format('Y/m/d H:i') }}</div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
