@php
    $stats = $this->getSummaryStats();
    $tabCounts = $this->getRoleCounts();
    $tabs = \App\Support\UserRoles::reportTabs();
@endphp

<x-filament::page>
    <div class="users-report-wrap" dir="rtl">
        <div class="users-report-stats">
            @foreach($stats as $stat)
                <div class="users-report-stat users-report-stat--{{ $stat['color'] }}">
                    <span class="users-report-stat-num">{{ number_format($stat['value']) }}</span>
                    <span class="users-report-stat-label">{{ $stat['label'] }}</span>
                </div>
            @endforeach
        </div>

        <div class="users-report-info">
            <h3>👥 هيكل المستخدمين في MG Plastic</h3>
            <div class="users-report-flow">
                <span class="users-report-chip users-report-chip--purple">مدير النظام</span>
                <span class="users-report-arrow">←</span>
                <span class="users-report-chip users-report-chip--blue">موزع جملة</span>
                <span class="users-report-arrow">←</span>
                <span class="users-report-chip users-report-chip--amber">تاجر قطاعي</span>
                <span class="users-report-arrow">←</span>
                <span class="users-report-chip users-report-chip--green">سباك</span>
            </div>
            <p>استخدم التبويبات أدناه للتصفية حسب نوع الحساب. يمكنك إضافة مستخدمين للوحة التحكم (مدير) مع صلاحيات محددة، أو إضافة شبكة التوزيع (موزع / تاجر / سباك).</p>
        </div>

        <div class="users-report-tabs">
            @foreach($tabs as $key => $tab)
                <button type="button"
                    wire:click="setRoleTab('{{ $key }}')"
                    class="users-report-tab {{ $roleTab === $key ? 'users-report-tab--active' : '' }}">
                    {{ $tab['label'] }}
                    <span class="users-report-tab-count">{{ number_format($tabCounts[$key] ?? 0) }}</span>
                </button>
            @endforeach
        </div>
    </div>

    {{ $this->table }}
</x-filament::page>
