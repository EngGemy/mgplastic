<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $priceList->title }} — {{ $priceList->number }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: 'Cairo', sans-serif;
            background: #e8edf5;
            color: #0f172a;
        }
        .toolbar {
            position: sticky;
            top: 0;
            z-index: 5;
            display: flex;
            gap: 10px;
            align-items: center;
            padding: 12px 20px;
            background: #0f172a;
            color: #fff;
        }
        .toolbar strong { flex: 1; font-weight: 700; }
        .toolbar button, .toolbar a {
            background: #fff;
            color: #0f172a;
            border: 0;
            border-radius: 999px;
            padding: 8px 16px;
            font-family: inherit;
            font-weight: 800;
            font-size: 14px;
            text-decoration: none;
            cursor: pointer;
        }
        .sheet {
            width: 210mm;
            min-height: 297mm;
            margin: 18px auto 40px;
            background: #fff;
            box-shadow: 0 18px 50px rgb(15 23 42 / 12%);
            padding: 14mm 12mm 24mm;
            position: relative;
        }
        .letterhead {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            padding-bottom: 12px;
            border-bottom: 3px solid #0f172a;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .brand img { height: 54px; width: auto; background: #0f172a; border-radius: 10px; padding: 6px 8px; }
        .brand-name { font-size: 22px; font-weight: 800; letter-spacing: 0.02em; line-height: 1; }
        .brand-sub { margin-top: 4px; color: #64748b; font-size: 12px; font-weight: 700; }
        .doc-no { text-align: left; }
        .doc-no span { display: block; color: #64748b; font-size: 11px; font-weight: 700; }
        .doc-no strong { font-size: 18px; }
        .title {
            margin: 18px 0 4px;
            text-align: center;
            font-size: 26px;
            font-weight: 800;
            line-height: 1.35;
        }
        .subtitle {
            text-align: center;
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 14px;
        }
        .meta {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
            font-size: 13px;
            font-weight: 700;
        }
        .to {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-right: 4px solid #1a56db;
            border-radius: 10px;
            padding: 10px 14px;
            margin-bottom: 14px;
        }
        .to small { color: #64748b; font-weight: 700; }
        .to strong { display: block; font-size: 16px; }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        thead { display: table-header-group; }
        th {
            background: #111827;
            color: #fff;
            font-weight: 800;
            padding: 8px 6px;
            border: 1px solid #111827;
            text-align: center;
            line-height: 1.3;
        }
        td {
            border: 1px solid #cbd5e1;
            padding: 7px 6px;
            text-align: center;
            font-weight: 700;
            vertical-align: middle;
        }
        td.name { text-align: right; font-weight: 800; }
        tbody tr:nth-child(even) td { background: #f8fafc; }
        .notes {
            margin-top: 14px;
            font-size: 13px;
            line-height: 1.7;
        }
        .footer {
            margin-top: 22px;
            background: #111827;
            color: #fff;
            border-radius: 999px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 8px 8px 8px 16px;
        }
        .phones {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 800;
            letter-spacing: 0.02em;
            direction: ltr;
        }
        .phone-icon {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #fff;
            color: #111827;
            display: grid;
            place-items: center;
            font-size: 14px;
        }
        .page-badge {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: #fff;
            color: #111827;
            display: grid;
            place-items: center;
            font-weight: 800;
        }
        .page-badge::after { content: counter(page); }
        @page { size: A4; margin: 12mm; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { width: auto; min-height: auto; margin: 0; box-shadow: none; padding: 0 0 16mm; }
            .footer { position: fixed; left: 0; right: 0; bottom: 0; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <strong>{{ $priceList->number }}</strong>
        <button type="button" onclick="window.print()">طباعة / حفظ PDF</button>
    </div>

    <article class="sheet">
        <header class="letterhead">
            <div class="brand">
                <img src="{{ asset('images/logo-light.png') }}" alt="MG Plastic">
                <div>
                    <div class="brand-name">MG Plastic</div>
                    <div class="brand-sub">قائمة أسعار</div>
                </div>
            </div>
            <div class="doc-no">
                <span>رقم القائمة</span>
                <strong>{{ $priceList->number }}</strong>
                <span>{{ $priceList->issued_on?->format('Y/m/d') }}</span>
                @if($priceList->valid_until)
                    <span>صالحة حتى {{ $priceList->valid_until->format('Y/m/d') }}</span>
                @endif
            </div>
        </header>

        <h1 class="title">{{ $priceList->title }}</h1>
        @if($priceList->subtitle)
            <div class="subtitle">{{ $priceList->subtitle }}</div>
        @endif

        @if($priceList->customerLabel() || $priceList->customer_phone)
            <div class="to">
                <small>مقدم إلى</small>
                <strong>{{ $priceList->customerLabel() ?: 'عميل' }}</strong>
                @if($priceList->customer_phone)
                    <span>{{ $priceList->customer_phone }}</span>
                @endif
            </div>
        @endif

        <table>
            <thead>
                <tr>
                    <th>ترتيب</th>
                    <th>نوع الصنف</th>
                    <th>عبوة الصندوق</th>
                    <th>سعر القطعة<br>(درهم)</th>
                    <th>سعر القطعة<br>(دينار)</th>
                    <th>سعر الصندوق<br>(درهم)</th>
                    <th>سعر الصندوق<br>(دينار)</th>
                </tr>
            </thead>
            <tbody>
                @forelse($priceList->items as $item)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td class="name">{{ $item->item_name }}</td>
                        <td>{{ $item->packLabel() }}</td>
                        <td>{{ $item->money('piece_price_aed') }}</td>
                        <td>{{ $item->money('piece_price_lyd') }}</td>
                        <td>{{ $item->money('box_price_aed') }}</td>
                        <td>{{ $item->money('box_price_lyd') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">لا توجد أصناف في هذه القائمة</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if($priceList->notes)
            <div class="notes">{{ $priceList->notes }}</div>
        @endif

        @if($phones !== [])
            <footer class="footer">
                <div class="phones">
                    <span class="phone-icon">☎</span>
                    <span>{{ implode(' - ', $phones) }}</span>
                </div>
                <div class="page-badge"></div>
            </footer>
        @endif
    </article>

    @if($autoPrint)
        <script>window.addEventListener('load', () => window.print());</script>
    @endif
</body>
</html>
