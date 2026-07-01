<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فاتورة {{ $invoice->number }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Cairo', sans-serif;
            background: #f3f4f6;
            color: #111827;
            line-height: 1.5;
        }
        .toolbar {
            background: #1a56db;
            color: #fff;
            padding: 0.75rem 1.5rem;
            display: flex;
            gap: 0.75rem;
            align-items: center;
            flex-wrap: wrap;
        }
        .toolbar a, .toolbar button {
            background: #fff;
            color: #1a56db;
            border: none;
            padding: 0.45rem 1rem;
            border-radius: 8px;
            font-family: inherit;
            font-weight: 700;
            font-size: 0.85rem;
            cursor: pointer;
            text-decoration: none;
        }
        .toolbar span { font-weight: 600; flex: 1; }
        .page {
            max-width: 800px;
            margin: 1.5rem auto;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 24px rgb(0 0 0 / 8%);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #1a56db, #1e40af);
            color: #fff;
            padding: 1.5rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
        }
        .brand { font-size: 1.5rem; font-weight: 800; }
        .brand-sub { font-size: 0.8rem; opacity: 0.85; margin-top: 0.25rem; }
        .inv-no { text-align: left; }
        .inv-no-label { font-size: 0.75rem; opacity: 0.85; }
        .inv-no-value { font-size: 1.35rem; font-weight: 800; letter-spacing: 0.02em; }
        .inv-serial { font-size: 0.8rem; margin-top: 0.25rem; opacity: 0.9; }
        .meta {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            padding: 1.25rem 2rem;
            border-bottom: 1px solid #e5e7eb;
            background: #f9fafb;
        }
        .meta-item label { display: block; font-size: 0.7rem; color: #6b7280; font-weight: 600; }
        .meta-item span { font-size: 0.9rem; font-weight: 700; }
        .section-title {
            padding: 0.75rem 2rem;
            font-weight: 800;
            font-size: 0.95rem;
            color: #1a56db;
            border-bottom: 2px solid #dbeafe;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.82rem;
        }
        thead th {
            background: #eff6ff;
            color: #1e40af;
            padding: 0.6rem 0.5rem;
            text-align: center;
            font-weight: 700;
            border-bottom: 2px solid #bfdbfe;
        }
        tbody td {
            padding: 0.55rem 0.5rem;
            text-align: center;
            border-bottom: 1px solid #e5e7eb;
        }
        tbody td:first-child { text-align: right; font-weight: 600; }
        tbody tr:nth-child(even) { background: #fafafa; }
        .col-product { text-align: right !important; min-width: 140px; }
        .col-points { color: #d97706; font-weight: 700; }
        .totals {
            padding: 1rem 2rem 1.5rem;
            display: grid;
            grid-template-columns: 1fr 280px;
            gap: 1.5rem;
            align-items: start;
        }
        .totals-box {
            background: #f0fdf4;
            border: 2px solid #bbf7d0;
            border-radius: 10px;
            padding: 1rem;
        }
        .totals-box.points {
            background: #fffbeb;
            border-color: #fde68a;
        }
        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 0.35rem 0;
            font-size: 0.88rem;
        }
        .total-row.grand {
            border-top: 2px dashed #86efac;
            margin-top: 0.5rem;
            padding-top: 0.65rem;
            font-size: 1.05rem;
            font-weight: 800;
            color: #059669;
        }
        .total-row.grand-points {
            border-top-color: #fcd34d;
            color: #d97706;
        }
        .footer-note {
            padding: 1rem 2rem 1.5rem;
            font-size: 0.75rem;
            color: #6b7280;
            border-top: 1px solid #e5e7eb;
            text-align: center;
        }
        .dist-note {
            padding: 0.75rem 2rem;
            background: #eff6ff;
            font-size: 0.8rem;
            color: #1e40af;
            font-weight: 600;
        }
        @media print {
            body { background: #fff; }
            .toolbar { display: none !important; }
            .page { margin: 0; box-shadow: none; border-radius: 0; max-width: 100%; }
            @page { margin: 12mm; }
        }
    </style>
</head>
<body>
    @if(($mode ?? 'print') === 'print')
        <div class="toolbar">
            <span>فاتورة {{ $invoice->number }}</span>
            <button type="button" onclick="window.print()">🖨️ طباعة</button>
            <a href="{{ route('admin.invoices.download', $invoice) }}">⬇️ تنزيل للنظام</a>
            <a href="{{ route('admin.invoices.export', $invoice) }}">📄 JSON</a>
            <a href="{{ \App\Filament\Support\NetworkPanelUrls::invoiceView($invoice) }}">← العودة</a>
        </div>
    @endif

    <div class="page">
        <div class="header" style="background:linear-gradient(135deg,#1a3a6e,#1a56db);color:white;padding:1.5rem 2rem;display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;">

            <div style="display:flex;align-items:center;gap:14px;">
                <div>
                    <img src="{{ asset('images/logo-light.png') }}"
                         style="height:48px;width:auto;object-fit:contain;"
                         alt="MG Plastic"
                         onerror="this.style.display='none'">
                </div>

                @php
                    $distributor = $invoice->wholesaleDistributor ?? $invoice->issuer ?? null;
                    $hasDistributorLogo = $distributor && filled($distributor->brand_logo);
                @endphp

                @if($distributor && ($hasDistributorLogo || filled($distributor->brand_name)))
                <div style="width:1px;background:rgba(255,255,255,0.3);height:48px;"></div>

                <div style="display:flex;align-items:center;gap:8px;">
                    @if($hasDistributorLogo)
                        <img src="{{ \Storage::disk('public')->url($distributor->brand_logo) }}"
                             style="width:44px;height:44px;border-radius:8px;object-fit:cover;border:2px solid rgba(255,255,255,0.4);"
                             alt="{{ $distributor->brand_name }}">
                    @else
                        @php
                            $initials = collect(explode(' ', $distributor->brand_name ?? $distributor->name))
                                ->take(2)->map(fn($w) => mb_substr($w, 0, 1))->implode('');
                        @endphp
                        <div style="width:44px;height:44px;border-radius:8px;background:rgba(255,255,255,0.2);
                            border:2px solid rgba(255,255,255,0.4);display:flex;align-items:center;
                            justify-content:center;font-size:16px;font-weight:800;color:white;">
                            {{ $initials }}
                        </div>
                    @endif
                    <div>
                        <div style="font-size:14px;font-weight:700;color:white;">
                            {{ $distributor->brand_name ?? $distributor->name }}
                        </div>
                        <div style="font-size:11px;background:rgba(255,255,255,0.2);
                            padding:2px 8px;border-radius:999px;margin-top:2px;display:inline-block;">
                            {{ $distributor->role === 'wholesale_distributor' ? 'موزع جملة معتمد' : 'مُصدِر الفاتورة' }}
                        </div>
                    </div>
                </div>
                @else
                <div>
                    <div class="brand" style="font-size:1.4rem;font-weight:800;">MG Plastic</div>
                    <div class="brand-sub" style="font-size:0.8rem;opacity:0.85;">مصنع أدوات السباكة — ليبيا</div>
                </div>
                @endif
            </div>

            <div class="inv-no" style="text-align:left;">
                <div class="inv-no-label" style="font-size:0.75rem;opacity:0.85;">رقم الفاتورة</div>
                <div class="inv-no-value" style="font-size:1.35rem;font-weight:800;">{{ $invoice->number ?? '—' }}</div>
                @if($invoice->serial_number)
                <div class="inv-serial" style="font-size:0.8rem;opacity:0.9;">#{{ $invoice->serial_number }}</div>
                @endif
                <div style="margin-top:6px;font-size:11px;background:rgba(255,255,255,0.2);
                    padding:2px 10px;border-radius:999px;display:inline-block;">
                    @if($invoice->invoice_type === 'wholesale_pos')
                        {{ $invoice->invoice_flow === 'outgoing' ? '📤 صادر — جملة إلى قطاعي' : '📥 وارد — مصنع إلى موزع' }}
                    @else
                        🔧 إيصال سباك
                    @endif
                </div>
            </div>
        </div>

        <div class="meta">
            <div class="meta-item">
                <label>التاريخ</label>
                <span>{{ $invoice->created_at?->timezone('Africa/Tripoli')->format('Y/m/d — H:i') }}</span>
            </div>
            <div class="meta-item">
                <label>نوع الفاتورة</label>
                <span>{{ $invoice->isWholesalePos() ? 'فاتورة موزع جملة' : 'إيصال سباك' }}</span>
            </div>
            <div class="meta-item">
                <label>الحالة</label>
                <span>
                    @switch($invoice->status)
                        @case('approved') معتمدة @break
                        @case('pending_review') قيد المراجعة @break
                        @case('rejected') مرفوضة @break
                        @default {{ $invoice->status }}
                    @endswitch
                </span>
            </div>
            @if($invoice->wholesaleDistributor)
                <div class="meta-item">
                    <label>موزع الجملة</label>
                    <span>{{ $invoice->wholesaleDistributor->name }}</span>
                </div>
            @endif
            @if($invoice->plumber)
                <div class="meta-item">
                    <label>السباك</label>
                    <span>{{ $invoice->plumber->name }}</span>
                </div>
            @endif
            @if($invoice->issuer)
                <div class="meta-item">
                    <label>أصدرها</label>
                    <span>{{ $invoice->issuer->name }}</span>
                </div>
            @endif
        </div>

        @if($invoice->isWholesalePos())
            <div class="dist-note">
                ⭐ هذه الفاتورة مرتبطة بنظام توزيع النقاط — الكميات والنقاط أدناه تُوزَّع: مصنع → جملة → قطاعي → سباك
            </div>
        @endif

        <div class="section-title">بنود الفاتورة</div>

        <table>
            <thead>
                <tr>
                    <th class="col-product">المنتج</th>
                    <th>الكمية</th>
                    <th>سعر الوحدة (د.ل)</th>
                    <th>إجمالي (د.ل)</th>
                    <th>نقاط/وحدة</th>
                    <th>إجمالي النقاط</th>
                </tr>
            </thead>
            <tbody>
                @forelse($invoice->items as $item)
                    <tr>
                        <td class="col-product">{{ localized_name($item->product, 'name') }}</td>
                        <td><strong>{{ number_format($item->quantity) }}</strong></td>
                        <td>{{ number_format($item->unit_price_cents / 100, 2) }}</td>
                        <td>{{ number_format($item->quantity * $item->unit_price_cents / 100, 2) }}</td>
                        <td>{{ number_format($item->points_per_unit, 2) }}</td>
                        <td class="col-points">{{ number_format($item->total_points) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">لا توجد بنود</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div class="totals">
            <div></div>
            <div>
                <div class="totals-box">
                    <div class="total-row">
                        <span>إجمالي الكميات</span>
                        <strong>{{ number_format($invoice->items->sum('quantity')) }} وحدة</strong>
                    </div>
                    <div class="total-row grand">
                        <span>إجمالي المبلغ</span>
                        <strong>{{ number_format($invoice->total_cents / 100, 2) }} د.ل</strong>
                    </div>
                </div>
                <div class="totals-box points" style="margin-top: 0.75rem;">
                    <div class="total-row">
                        <span>إجمالي النقاط (للتوزيع)</span>
                        <strong>{{ number_format($invoice->items->sum('total_points')) }} نقطة</strong>
                    </div>
                </div>
            </div>
        </div>

        <div class="footer-note">
            MG Plastic — {{ now()->timezone('Africa/Tripoli')->format('Y/m/d H:i') }}
            — رقم {{ $invoice->number }} / {{ $invoice->serial_number }}
        </div>
    </div>

    @if(!empty($autoPrint) && ($mode ?? 'print') === 'print')
        <script>window.addEventListener('load', () => window.print());</script>
    @endif
</body>
</html>
