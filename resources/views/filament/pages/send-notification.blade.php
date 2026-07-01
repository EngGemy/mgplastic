<x-filament-panels::page>
    <form wire:submit="send">
        {{ $this->form }}

        <div class="mt-6 flex justify-start">
            <x-filament::button type="submit" icon="heroicon-o-paper-airplane">
                إرسال الإشعار
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
