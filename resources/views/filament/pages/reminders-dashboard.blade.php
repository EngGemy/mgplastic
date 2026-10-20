<x-filament-panels::page>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6" dir="rtl">
        <div class="border border-gray-200 bg-white p-4">
            <div class="text-xs text-gray-500">مرسل اليوم</div>
            <div class="text-3xl font-bold">{{ $this->stats['sent'] }}</div>
        </div>
        <div class="border border-gray-200 bg-white p-4">
            <div class="text-xs text-gray-500">تخطي</div>
            <div class="text-3xl font-bold">{{ $this->stats['skipped'] }}</div>
        </div>
        <div class="border border-gray-200 bg-white p-4">
            <div class="text-xs text-gray-500">فشل</div>
            <div class="text-3xl font-bold text-red-700">{{ $this->stats['failed'] }}</div>
        </div>
        <div class="border border-gray-200 bg-white p-4">
            <div class="text-xs text-gray-500">استخدام AI</div>
            <div class="text-3xl font-bold">{{ $this->stats['ai'] }}</div>
        </div>
    </div>

    <form class="mb-6 space-y-4">
        {{ $this->form }}
    </form>

    <x-filament::button wire:click="previewGemini" class="mb-4">معاينة رسالة Gemini</x-filament::button>
    @if($previewText)
        <div class="border border-gray-300 bg-white p-4 mb-6 whitespace-pre-wrap text-sm" dir="rtl">{{ $previewText }}</div>
    @endif

    <div class="border border-gray-200 bg-white overflow-hidden" dir="rtl">
        <div class="px-4 py-3 font-bold border-b">سجل التذكيرات</div>
        <ul class="divide-y text-sm">
            @foreach($this->logs as $log)
                <li class="px-4 py-2 flex justify-between gap-3">
                    <span>#{{ $log->user_id }} · {{ $log->kind }} · {{ $log->status }} @if($log->ai_used)(AI)@endif</span>
                    <span class="text-gray-500">{{ $log->created_at }}</span>
                </li>
            @endforeach
        </ul>
    </div>
</x-filament-panels::page>
