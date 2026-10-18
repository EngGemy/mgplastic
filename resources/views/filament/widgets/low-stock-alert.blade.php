<div
    wire:ignore.self
    class="fi-wi-low-stock space-y-3"
    x-data="{ open: @js($showPopup) }"
    x-init="if (open) { $nextTick(() => open = true) }"
>
    @if ($count > 0)
        <div class="rounded-xl border border-amber-300 bg-amber-50 dark:bg-amber-950/40 dark:border-amber-700 p-4">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-base font-bold text-amber-900 dark:text-amber-100">
                        تنبيه للكميات القليلة
                    </h3>
                    <p class="mt-1 text-sm text-amber-800 dark:text-amber-200">
                        {{ $count }} منتج(ات) مخزونها ≤ {{ $threshold }} وحدات (قرب الصفر).
                    </p>
                </div>
                <button
                    type="button"
                    class="text-sm font-semibold text-amber-800 underline dark:text-amber-200"
                    @click="open = true"
                >
                    عرض القائمة
                </button>
            </div>

            <ul class="mt-3 divide-y divide-amber-200 dark:divide-amber-800 max-h-48 overflow-y-auto">
                @foreach (array_slice($items, 0, 8) as $row)
                    <li class="flex items-center justify-between py-2 text-sm">
                        <span class="font-medium text-gray-900 dark:text-gray-100">{{ $row['name'] }}</span>
                        <span @class([
                            'font-bold tabular-nums',
                            'text-danger-600' => $row['available_qty'] <= 0,
                            'text-warning-600' => $row['available_qty'] > 0,
                        ])>
                            {{ $row['available_qty'] }}
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div
        x-show="open"
        x-cloak
        class="fixed inset-0 z-[80] flex items-center justify-center p-4"
        style="display: none;"
    >
        <div class="absolute inset-0 bg-gray-950/50" @click="open = false; $wire.dismissPopup()"></div>
        <div class="relative w-full max-w-lg rounded-2xl bg-white dark:bg-gray-900 shadow-xl border border-gray-200 dark:border-gray-700 p-5">
            <div class="flex items-center justify-between gap-3 mb-4">
                <h3 class="text-lg font-bold text-gray-950 dark:text-white">تنبيه للكميات القليلة</h3>
                <button
                    type="button"
                    class="rounded-lg px-2 py-1 text-sm text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800"
                    @click="open = false; $wire.dismissPopup()"
                >
                    إغلاق
                </button>
            </div>
            <p class="text-sm text-gray-600 dark:text-gray-300 mb-3">
                المنتجات التالية مخزونها قرب الصفر — راجع التوريد قبل نفاد الكمية.
            </p>
            <ul class="max-h-80 overflow-y-auto divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($items as $row)
                    <li class="flex items-center justify-between py-2.5">
                        <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $row['name'] }}</span>
                        <span class="text-sm font-bold {{ $row['available_qty'] <= 0 ? 'text-danger-600' : 'text-warning-600' }}">
                            المتبقي: {{ $row['available_qty'] }}
                        </span>
                    </li>
                @empty
                    <li class="py-6 text-center text-sm text-gray-500">لا توجد منتجات منخفضة حالياً</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>
