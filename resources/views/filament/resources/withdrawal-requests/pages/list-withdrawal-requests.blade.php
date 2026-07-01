<x-filament-panels::page>
    @php
        $counts = $this->getStatusCounts();
        $tabs = [
            'all' => 'الكل',
            'pending' => 'قيد المراجعة',
            'paid' => 'مدفوعة',
            'rejected' => 'مرفوضة',
        ];
    @endphp

    <div class="withdrawals-wrap">
        <div class="withdrawals-stats">
            <div class="withdrawals-stat withdrawals-stat--amber">
                <span class="withdrawals-stat-num">{{ number_format($counts['pending']) }}</span>
                <span class="withdrawals-stat-label">قيد المراجعة</span>
            </div>
            <div class="withdrawals-stat withdrawals-stat--blue">
                <span class="withdrawals-stat-num">{{ $this->getPendingAmountLabel() }}</span>
                <span class="withdrawals-stat-label">مبالغ معلّقة</span>
            </div>
            <div class="withdrawals-stat withdrawals-stat--green">
                <span class="withdrawals-stat-num">{{ number_format($counts['paid']) }}</span>
                <span class="withdrawals-stat-label">مدفوعة</span>
            </div>
            <div class="withdrawals-stat withdrawals-stat--red">
                <span class="withdrawals-stat-num">{{ number_format($counts['rejected']) }}</span>
                <span class="withdrawals-stat-label">مرفوضة</span>
            </div>
        </div>

        <div class="withdrawals-tabs">
            @foreach($tabs as $key => $label)
                <button
                    type="button"
                    wire:click="setStatusTab('{{ $key }}')"
                    @class(['withdrawals-tab', 'withdrawals-tab--active' => $statusTab === $key])
                >
                    {{ $label }}
                    <span class="withdrawals-tab-count">{{ number_format($counts[$key]) }}</span>
                </button>
            @endforeach
        </div>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
