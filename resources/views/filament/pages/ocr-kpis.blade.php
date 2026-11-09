<x-filament-panels::page>
    @php($d = $this->getViewData())
    <div class="grid gap-4 md:grid-cols-3">
        <x-filament::section>
            <x-slot name="heading">اليوم</x-slot>
            <ul class="text-sm space-y-1">
                <li>دفعات: {{ $d['batchesToday'] }}</li>
                <li>مطبّقة: {{ $d['addedToday'] }}</li>
                <li>متروكة: {{ $d['abandoned'] }}</li>
                <li>نسبة الترك: {{ $d['batchesToday'] ? round($d['abandoned'] / max(1, $d['batchesToday']) * 100, 1) : 0 }}%</li>
            </ul>
        </x-filament::section>
        <x-filament::section>
            <x-slot name="heading">المطابقة</x-slot>
            <ul class="text-sm space-y-1">
                <li>بنود اليوم: {{ $d['totalLines'] }}</li>
                <li>جاهز تلقائي: {{ $d['autoReady'] }} ({{ $d['totalLines'] ? round($d['autoReady'] / max(1,$d['totalLines']) * 100, 1) : 0 }}%)</li>
                <li>بنود معدّلة/معتمدة: {{ $d['edited'] }}</li>
            </ul>
        </x-filament::section>
        <x-filament::section>
            <x-slot name="heading">Gemini</x-slot>
            <ul class="text-sm space-y-1">
                <li>استدعاءات اليوم: {{ $d['geminiCalls'] }}</li>
                <li>فشل: {{ $d['geminiFail'] }}</li>
                <li>متوسط المدة: {{ $d['avgMs'] }} ms</li>
                <li>تكلفة تقديرية: ~{{ number_format($d['geminiCalls'] * 0.002, 3) }}$ (تقريبي)</li>
            </ul>
        </x-filament::section>
    </div>

    <x-filament::section class="mt-4">
        <x-slot name="heading">أكثر النصوص غير المطابقة (7 أيام)</x-slot>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr><th class="text-right p-2">النص</th><th class="text-right p-2">العدد</th></tr></thead>
                <tbody>
                @forelse ($d['topUnmatched'] as $row)
                    <tr class="border-t"><td class="p-2">{{ $row->raw_fragment }}</td><td class="p-2">{{ $row->c }}</td></tr>
                @empty
                    <tr><td class="p-2" colspan="2">لا بيانات</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section class="mt-4">
        <x-slot name="heading">آخر الأسماء المستفادة (aliases)</x-slot>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr><th class="p-2 text-right">النص</th><th class="p-2 text-right">منتج</th><th class="p-2 text-right">نطاق</th><th class="p-2 text-right">hits</th></tr></thead>
                <tbody>
                @foreach ($d['aliases'] as $a)
                    <tr class="border-t">
                        <td class="p-2">{{ $a->normalized_text }}</td>
                        <td class="p-2">#{{ $a->product_id }}</td>
                        <td class="p-2">{{ $a->scope ?? 'global' }}</td>
                        <td class="p-2">{{ $a->hits }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="text-xs mt-2 opacity-70">لحذف alias استخدم جدول ocr_aliases من Filament/DB — صفحة المراجعة التفصيلية عبر OcrTermAlias أيضاً.</p>
    </x-filament::section>
</x-filament-panels::page>
