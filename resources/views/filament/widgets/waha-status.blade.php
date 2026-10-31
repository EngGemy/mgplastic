@php($status = $this->getStatus())
<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex flex-wrap items-center gap-3 text-sm">
            <span @class([
                'inline-flex items-center rounded-md px-2.5 py-1 font-semibold',
                'bg-success-100 text-success-700 dark:bg-success-500/20 dark:text-success-400' => $status['ok'] === true,
                'bg-danger-100 text-danger-700 dark:bg-danger-500/20 dark:text-danger-400' => $status['ok'] === false,
                'bg-gray-100 text-gray-700 dark:bg-gray-500/20 dark:text-gray-300' => $status['ok'] === null,
            ])>
                {{ $status['label'] }}
            </span>
            <span class="text-gray-600 dark:text-gray-300">{{ $status['reason'] }}</span>
            @if ($status['since'])
                <span class="text-gray-400">since {{ $status['since'] }}</span>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
