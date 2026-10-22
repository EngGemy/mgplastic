<x-filament-panels::page>
    <form wire:submit="send">
        {{ $this->form }}

        <div class="mt-6 flex flex-wrap items-center gap-3">
            <x-filament::button type="submit" icon="heroicon-o-paper-airplane" color="primary">
                إرسال الإشعار الآن
            </x-filament::button>

            <x-filament::button
                tag="a"
                :href="\App\Filament\Resources\NotificationBroadcastResource::getUrl()"
                color="gray"
                icon="heroicon-o-archive-box"
            >
                مكتبة الإرسال
            </x-filament::button>

            <x-filament::button
                tag="a"
                :href="\App\Filament\Resources\DatabaseNotificationResource::getUrl()"
                color="gray"
                icon="heroicon-o-bell"
            >
                سجل كل الإشعارات
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>

