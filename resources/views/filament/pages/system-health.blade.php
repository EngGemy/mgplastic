@php
    $checks = $this->getChecks();
    $failedJobs = $this->getFailedJobs();
    $queues = collect($failedJobs)->pluck('queue')->unique()->values();
    $overview = $this->getOverview();
    $checkedAt = $this->getSnapshotCheckedAt();
    $checkedLabel = $checkedAt
        ? \Illuminate\Support\Carbon::parse($checkedAt)->timezone(config('app.timezone', 'Africa/Tripoli'))->format('Y/m/d H:i')
        : 'لا توجد لقطة بعد — اضغط تحديث مباشر';
    $statusAr = [
        'ok' => 'سليم',
        'warning' => 'تحذير',
        'critical' => 'حرج',
    ];
@endphp

<x-filament-panels::page>
<style>
    .sh-ops {
        --sh-ink: #0f2744;
        --sh-muted: #5b6b7c;
        --sh-line: #d7dee8;
        --sh-surface: #f4f6f9;
        --sh-paper: #ffffff;
        --sh-ok: #0f766e;
        --sh-ok-soft: #d9f3ef;
        --sh-warn: #b45309;
        --sh-warn-soft: #ffedd5;
        --sh-crit: #b91c1c;
        --sh-crit-soft: #fee2e2;
        --sh-action: #1a56db;
        direction: rtl;
        font-family: 'Cairo', sans-serif;
        color: var(--sh-ink);
        display: grid;
        gap: 1.25rem;
    }
    .sh-ops * { box-sizing: border-box; }

    .sh-mast {
        position: relative;
        overflow: hidden;
        border: 1px solid var(--sh-line);
        background:
            linear-gradient(120deg, rgba(15,39,68,.04), transparent 42%),
            repeating-linear-gradient(
                -12deg,
                transparent,
                transparent 10px,
                rgba(15,39,68,.025) 10px,
                rgba(15,39,68,.025) 11px
            ),
            var(--sh-paper);
        padding: 1.25rem 1.35rem 1.15rem;
    }
    .sh-mast::before {
        content: '';
        position: absolute;
        inset-inline-start: 0;
        top: 0;
        bottom: 0;
        width: 5px;
        background: var(--sh-ok);
    }
    .sh-mast.is-warning::before { background: var(--sh-warn); }
    .sh-mast.is-critical::before { background: var(--sh-crit); }

    .sh-mast-top {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
    }
    .sh-kicker {
        font-size: .78rem;
        font-weight: 700;
        color: var(--sh-muted);
        margin-bottom: .35rem;
    }
    .sh-title {
        margin: 0;
        font-size: clamp(1.45rem, 2.4vw, 1.9rem);
        font-weight: 800;
        line-height: 1.2;
        letter-spacing: -.01em;
    }
    .sh-lead {
        margin: .45rem 0 0;
        max-width: 38rem;
        color: var(--sh-muted);
        font-size: .92rem;
        line-height: 1.55;
    }

    .sh-verdict {
        min-width: 11.5rem;
        padding: .85rem 1rem;
        border: 1px solid var(--sh-line);
        background: var(--sh-surface);
        text-align: center;
    }
    .sh-verdict.is-ok { background: var(--sh-ok-soft); border-color: #99d5cb; }
    .sh-verdict.is-warning { background: var(--sh-warn-soft); border-color: #fdba74; }
    .sh-verdict.is-critical { background: var(--sh-crit-soft); border-color: #fca5a5; }
    .sh-verdict-word {
        display: block;
        font-size: 1.35rem;
        font-weight: 900;
        line-height: 1;
        margin-bottom: .35rem;
    }
    .sh-verdict.is-ok .sh-verdict-word { color: var(--sh-ok); }
    .sh-verdict.is-warning .sh-verdict-word { color: var(--sh-warn); }
    .sh-verdict.is-critical .sh-verdict-word { color: var(--sh-crit); }
    .sh-verdict-meta {
        font-size: .75rem;
        color: var(--sh-muted);
        font-weight: 600;
    }

    .sh-counts {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: .65rem;
        margin-top: 1rem;
    }
    .sh-count {
        border: 1px solid var(--sh-line);
        background: var(--sh-paper);
        padding: .7rem .85rem;
        display: flex;
        flex-direction: column;
        gap: .15rem;
    }
    .sh-count strong {
        font-size: 1.35rem;
        font-weight: 800;
        line-height: 1;
    }
    .sh-count span {
        font-size: .75rem;
        color: var(--sh-muted);
        font-weight: 600;
    }
    .sh-count.is-crit strong { color: var(--sh-crit); }
    .sh-count.is-warn strong { color: var(--sh-warn); }
    .sh-count.is-ok strong { color: var(--sh-ok); }

    .sh-actions {
        display: flex;
        flex-wrap: wrap;
        gap: .55rem;
        align-items: center;
    }
    .sh-btn {
        appearance: none;
        border: 1px solid var(--sh-line);
        background: var(--sh-paper);
        color: var(--sh-ink);
        font: inherit;
        font-size: .85rem;
        font-weight: 700;
        padding: .55rem .9rem;
        cursor: pointer;
        transition: background .15s ease, border-color .15s ease, color .15s ease;
    }
    .sh-btn:hover { background: var(--sh-surface); }
    .sh-btn:focus-visible {
        outline: 2px solid var(--sh-action);
        outline-offset: 2px;
    }
    .sh-btn--primary {
        background: var(--sh-action);
        border-color: var(--sh-action);
        color: #fff;
    }
    .sh-btn--primary:hover { filter: brightness(1.05); background: var(--sh-action); }
    .sh-btn--warn {
        background: #fff7ed;
        border-color: #fdba74;
        color: var(--sh-warn);
    }
    .sh-stamp {
        margin-inline-start: auto;
        font-size: .75rem;
        color: var(--sh-muted);
        font-weight: 600;
    }

    .sh-panel {
        border: 1px solid var(--sh-line);
        background: var(--sh-paper);
    }
    .sh-panel-head {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: .75rem;
        padding: .85rem 1rem;
        border-bottom: 1px solid var(--sh-line);
        background: var(--sh-surface);
    }
    .sh-panel-head h2 {
        margin: 0;
        font-size: 1rem;
        font-weight: 800;
    }
    .sh-panel-head p {
        margin: .2rem 0 0;
        font-size: .78rem;
        color: var(--sh-muted);
        font-weight: 600;
    }

    .sh-checks { display: grid; }
    .sh-check {
        display: grid;
        grid-template-columns: minmax(9rem, 14rem) auto 1fr;
        gap: .75rem 1rem;
        align-items: start;
        padding: .95rem 1rem;
        border-bottom: 1px solid var(--sh-line);
    }
    .sh-check:last-child { border-bottom: 0; }
    .sh-check-name {
        font-weight: 800;
        font-size: .92rem;
        line-height: 1.35;
    }
    .sh-check-key {
        display: block;
        margin-top: .2rem;
        font-size: .68rem;
        color: var(--sh-muted);
        font-weight: 600;
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    }
    .sh-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 3.6rem;
        padding: .22rem .55rem;
        font-size: .72rem;
        font-weight: 800;
        border: 1px solid transparent;
        white-space: nowrap;
    }
    .sh-pill.is-ok { background: var(--sh-ok-soft); color: var(--sh-ok); border-color: #99d5cb; }
    .sh-pill.is-warning { background: var(--sh-warn-soft); color: var(--sh-warn); border-color: #fdba74; }
    .sh-pill.is-critical { background: var(--sh-crit-soft); color: var(--sh-crit); border-color: #fca5a5; }
    .sh-check-detail {
        font-size: .86rem;
        line-height: 1.5;
        color: var(--sh-ink);
        word-break: break-word;
    }
    .sh-check-time {
        display: block;
        margin-top: .25rem;
        font-size: .7rem;
        color: var(--sh-muted);
        font-weight: 600;
    }

    .sh-result {
        border: 1px solid var(--sh-line);
        background: #0f2744;
        color: #e8eef7;
        padding: .9rem 1rem;
    }
    .sh-result h3 {
        margin: 0 0 .55rem;
        font-size: .85rem;
        font-weight: 800;
        color: #9eb6d4;
    }
    .sh-result pre {
        margin: 0;
        white-space: pre-wrap;
        word-break: break-word;
        font-size: .75rem;
        line-height: 1.5;
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        max-height: 14rem;
        overflow: auto;
    }

    .sh-table-wrap { overflow-x: auto; }
    .sh-table {
        width: 100%;
        border-collapse: collapse;
        font-size: .82rem;
    }
    .sh-table th {
        text-align: right;
        background: var(--sh-surface);
        color: var(--sh-muted);
        font-weight: 800;
        font-size: .72rem;
        padding: .65rem .85rem;
        border-bottom: 1px solid var(--sh-line);
        white-space: nowrap;
    }
    .sh-table td {
        padding: .75rem .85rem;
        border-bottom: 1px solid var(--sh-line);
        vertical-align: top;
    }
    .sh-table tr:last-child td { border-bottom: 0; }
    .sh-table tr:hover td { background: #fafbfc; }
    .sh-mono {
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        font-size: .75rem;
    }
    .sh-error {
        max-width: 28rem;
        line-height: 1.45;
        word-break: break-word;
        color: #334155;
    }
    .sh-row-actions {
        display: flex;
        flex-wrap: wrap;
        gap: .35rem;
    }
    .sh-btn--sm { padding: .35rem .65rem; font-size: .75rem; }
    .sh-btn--danger {
        background: #fff5f5;
        border-color: #fecaca;
        color: var(--sh-crit);
    }
    .sh-btn--ok {
        background: var(--sh-ok-soft);
        border-color: #99d5cb;
        color: var(--sh-ok);
    }
    .sh-empty {
        padding: 2rem 1rem;
        text-align: center;
        color: var(--sh-muted);
        font-weight: 600;
    }

    @media (max-width: 900px) {
        .sh-check {
            grid-template-columns: 1fr auto;
            grid-template-areas:
                "name pill"
                "detail detail";
        }
        .sh-check-name { grid-area: name; }
        .sh-check .sh-pill { grid-area: pill; justify-self: end; }
        .sh-check-detail { grid-area: detail; }
        .sh-counts { grid-template-columns: 1fr; }
        .sh-stamp { margin-inline-start: 0; width: 100%; }
    }

    @media (prefers-reduced-motion: reduce) {
        .sh-btn { transition: none; }
    }
</style>

<div class="sh-ops">
    <section class="sh-mast is-{{ $overview['verdict'] }}" aria-labelledby="sh-ops-title">
        <div class="sh-mast-top">
            <div>
                <div class="sh-kicker">MG Plastic · تشغيل واتساب والطوابير والإشعارات</div>
                <h1 class="sh-title" id="sh-ops-title">غرفة المراقبة</h1>
                <p class="sh-lead">{{ $overview['label'] }}. راجع الفحوصات ثم نفّذ الإجراء المناسب دون تخمين.</p>
            </div>
            <div class="sh-verdict is-{{ $overview['verdict'] }}" role="status">
                <span class="sh-verdict-word">{{ $statusAr[$overview['verdict']] ?? $overview['verdict'] }}</span>
                <span class="sh-verdict-meta">حالة النظام الآن</span>
            </div>
        </div>

        <div class="sh-counts" aria-label="ملخص الحالات">
            <div class="sh-count is-crit">
                <strong>{{ $overview['critical'] }}</strong>
                <span>حرج</span>
            </div>
            <div class="sh-count is-warn">
                <strong>{{ $overview['warning'] }}</strong>
                <span>تحذير</span>
            </div>
            <div class="sh-count is-ok">
                <strong>{{ $overview['ok'] }}</strong>
                <span>سليم</span>
            </div>
        </div>
    </section>

    <div class="sh-actions">
        <button type="button" class="sh-btn sh-btn--primary" wire:click="refreshChecks" wire:loading.attr="disabled">
            تحديث مباشر (يشمل WAHA)
        </button>
        <button
            type="button"
            class="sh-btn sh-btn--warn"
            wire:click="resetWhatsAppBreaker"
            wire:confirm="هل تريد إعادة ضبط قفل واتساب (Circuit Breaker)؟"
        >
            إعادة ضبط قفل واتساب
        </button>
        <button type="button" class="sh-btn" wire:click="sendTestWhatsApp">
            إرسال واتساب تجريبي
        </button>
        <span class="sh-stamp">آخر لقطة: {{ $checkedLabel }}</span>
    </div>

    @if($this->testWhatsAppResult)
        <div class="sh-result">
            <h3>نتيجة اختبار واتساب</h3>
            <pre>{{ $this->testWhatsAppResult }}</pre>
        </div>
    @endif

    <section class="sh-panel" aria-labelledby="sh-checks-title">
        <div class="sh-panel-head">
            <div>
                <h2 id="sh-checks-title">الفحوصات</h2>
                <p>كل صف = مصدر واحد. اللون يعني مستوى الخطورة وليس زخرفة.</p>
            </div>
        </div>
        <div class="sh-checks">
            @foreach($checks as $check)
                @php
                    $status = $check['status'] ?? 'ok';
                    $valueText = is_scalar($check['value'] ?? null) || ($check['value'] ?? null) === null
                        ? (string) ($check['value'] ?? '')
                        : json_encode($check['value'], JSON_UNESCAPED_UNICODE);
                @endphp
                <article class="sh-check" wire:key="check-{{ $check['key'] ?? $loop->index }}">
                    <div class="sh-check-name">
                        {{ $check['label'] ?? $check['key'] }}
                        @if(!empty($check['key']))
                            <span class="sh-check-key">{{ $check['key'] }}</span>
                        @endif
                    </div>
                    <span class="sh-pill is-{{ $status }}">{{ $statusAr[$status] ?? $status }}</span>
                    <div class="sh-check-detail">
                        {{ $check['details'] ?? '—' }}
                        @if($valueText !== '' && $valueText !== '—')
                            <span class="sh-check-time">قيمة: {{ \Illuminate\Support\Str::limit($valueText, 120) }}</span>
                        @endif
                        @if(!empty($check['checked_at']))
                            <span class="sh-check-time">
                                فُحص: {{ \Illuminate\Support\Carbon::parse($check['checked_at'])->timezone(config('app.timezone', 'Africa/Tripoli'))->format('Y/m/d H:i:s') }}
                            </span>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    </section>

    <section class="sh-panel" aria-labelledby="sh-failed-title">
        <div class="sh-panel-head">
            <div>
                <h2 id="sh-failed-title">المهام الفاشلة</h2>
                <p>أعد المحاولة لطابور كامل أو لمهمة واحدة، أو احذف السجل بعد المعالجة.</p>
            </div>
            <div class="sh-actions">
                @foreach($queues as $queue)
                    <button
                        type="button"
                        class="sh-btn sh-btn--sm sh-btn--warn"
                        wire:click="retryFailedQueue({{ \Illuminate\Support\Js::from($queue) }})"
                        wire:confirm="إعادة كل مهام طابور {{ $queue }} الفاشلة؟"
                    >
                        إعادة طابور {{ $queue }}
                    </button>
                @endforeach
            </div>
        </div>

        <div class="sh-table-wrap">
            <table class="sh-table">
                <thead>
                    <tr>
                        <th>المعرّف</th>
                        <th>الطابور</th>
                        <th>المهمة</th>
                        <th>وقت الفشل</th>
                        <th>السبب</th>
                        <th>إجراء</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($failedJobs as $job)
                        <tr wire:key="failed-{{ $job['id'] }}">
                            <td class="sh-mono">#{{ $job['id'] }}</td>
                            <td>{{ $job['queue'] }}</td>
                            <td class="sh-mono">{{ class_basename($job['job_class']) }}</td>
                            <td class="sh-mono">{{ $job['failed_at'] }}</td>
                            <td class="sh-error">{{ $job['error'] }}</td>
                            <td>
                                <div class="sh-row-actions">
                                    <button type="button" class="sh-btn sh-btn--sm sh-btn--ok" wire:click="retryFailedJob({{ $job['id'] }})">
                                        إعادة
                                    </button>
                                    <button
                                        type="button"
                                        class="sh-btn sh-btn--sm sh-btn--danger"
                                        wire:click="deleteFailedJob({{ $job['id'] }})"
                                        wire:confirm="حذف المهمة الفاشلة #{{ $job['id'] }}؟"
                                    >
                                        حذف
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="sh-empty">لا توجد مهام فاشلة حالياً — النظام نظيف من هذه الجهة.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
</x-filament-panels::page>
