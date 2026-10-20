<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">سباكو شبكتي</x-slot>
        <div class="overflow-x-auto" dir="rtl">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-right text-gray-500">
                        <th class="py-2">السباك</th>
                        <th>الدرجة</th>
                        <th>المستوى</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $row)
                        <tr class="border-b border-gray-100">
                            <td class="py-2 font-medium">{{ $row->user?->name }}</td>
                            <td class="text-2xl font-bold">{{ $row->score }}</td>
                            <td>{{ $row->levelLabel() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="py-4 text-gray-500">لا بيانات بعد</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($weekTop)
            <p class="mt-4 text-sm text-gray-600">أفضل سباك: <strong>{{ $weekTop->user?->name }}</strong> ({{ $weekTop->score }})</p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
