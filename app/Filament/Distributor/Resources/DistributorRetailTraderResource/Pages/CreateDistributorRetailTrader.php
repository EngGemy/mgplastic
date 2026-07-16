<?php

namespace App\Filament\Distributor\Resources\DistributorRetailTraderResource\Pages;

use App\Filament\Distributor\Resources\DistributorRetailTraderResource;
use App\Models\City;
use App\Models\Country;
use App\Models\User;
use App\Services\NetworkCodeService;
use App\Services\RetailNetworkLinkService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Collection;

class CreateDistributorRetailTrader extends Page
{
    protected static string $resource = DistributorRetailTraderResource::class;

    protected static string $view = 'filament.distributor.pages.add-retail-trader';

    protected static ?string $title = 'إضافة تاجر قطاعي';

    /**
     * Filament/Livewire may hydrate a legacy `$data` payload from CreateRecord
     * routes — keep it declared so requests do not 500.
     *
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public string $mode = 'existing';

    public string $lookupCode = '';

    public ?array $preview = null;

    public ?string $name = null;

    public ?string $phone = null;

    public ?string $email = null;

    public ?string $password = null;

    public ?string $brand_name = null;

    public ?string $address = null;

    public ?int $country_id = null;

    public ?int $city_id = null;

    public function getHeading(): string
    {
        return 'إضافة تاجر قطاعي لشبكتك';
    }

    public function setMode(string $mode): void
    {
        $this->mode = in_array($mode, ['existing', 'new'], true) ? $mode : 'existing';
        $this->preview = null;
        $this->resetErrorBag();
    }

    public function lookup(): void
    {
        $this->preview = null;
        $raw = trim($this->lookupCode);

        if ($raw === '') {
            Notification::make()->warning()->title('أدخل الرقم الموحّد أو رقم الهاتف')->send();

            return;
        }

        $codes = app(NetworkCodeService::class);
        $needle = $codes->normalize($raw);

        $retail = $codes->findByCode($needle)
            ?? User::query()->where('phone', $raw)->first()
            ?? User::query()->where('phone', $needle)->first();

        if (! $retail || ! $retail->isRetailTrader()) {
            Notification::make()
                ->danger()
                ->title('لم يُعثر على تاجر قطاعي')
                ->body('تأكد من الرقم الموحّد (مثل MG-R-000012) أو سجّل تاجراً جديداً.')
                ->send();

            return;
        }

        app(NetworkCodeService::class)->ensure($retail);

        $this->preview = [
            'id' => $retail->id,
            'name' => $retail->name,
            'brand_name' => $retail->brand_name,
            'phone' => $retail->phone,
            'network_code' => $retail->network_code,
            'already_linked' => app(RetailNetworkLinkService::class)->isLinked(auth()->user(), $retail),
            'is_active' => (bool) $retail->is_active,
            'is_approved' => (bool) $retail->is_approved,
        ];
    }

    public function confirmLink(): void
    {
        if (! $this->preview) {
            Notification::make()->warning()->title('ابحث عن التاجر أولاً')->send();

            return;
        }

        try {
            $result = app(RetailNetworkLinkService::class)->linkByCode(
                wholesaler: auth()->user(),
                codeOrPhone: $this->preview['network_code'] ?? $this->lookupCode,
                linkedBy: auth()->user(),
            );

            Notification::make()
                ->success()
                ->title($result['created_link'] ? 'تمت الإضافة لشبكتك ✓' : 'موجود في شبكتك')
                ->body($result['message'])
                ->send();

            $this->redirect(DistributorRetailTraderResource::getUrl('view', ['record' => $result['retail']]));
        } catch (\DomainException $e) {
            Notification::make()->danger()->title('تعذّر الربط')->body($e->getMessage())->send();
        }
    }

    public function registerNew(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'min:6'],
            'email' => ['nullable', 'email', 'max:255'],
            'brand_name' => ['nullable', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
        ], [], [
            'name' => 'الاسم',
            'phone' => 'الهاتف',
            'password' => 'كلمة المرور',
        ]);

        try {
            $retail = app(RetailNetworkLinkService::class)->registerAndLink(
                wholesaler: auth()->user(),
                data: [
                    'name' => $this->name,
                    'phone' => $this->phone,
                    'password' => $this->password,
                    'email' => $this->email,
                    'brand_name' => $this->brand_name,
                    'address' => $this->address,
                    'country_id' => $this->country_id,
                    'city_id' => $this->city_id,
                ],
                createdBy: auth()->user(),
            );

            Notification::make()
                ->success()
                ->title('تم تسجيل التاجر ✓')
                ->body("الرقم الموحّد: {$retail->network_code}")
                ->persistent()
                ->send();

            $this->redirect(DistributorRetailTraderResource::getUrl('view', ['record' => $retail]));
        } catch (\DomainException $e) {
            Notification::make()->danger()->title('تعذّر التسجيل')->body($e->getMessage())->send();
        }
    }

    public function getCountriesProperty(): Collection
    {
        return Country::query()->orderBy('name_ar')->get();
    }

    public function getCitiesProperty(): Collection
    {
        if (! $this->country_id) {
            return collect();
        }

        return City::query()->where('country_id', $this->country_id)->orderBy('name_ar')->get();
    }

    public function updatedCountryId(): void
    {
        $this->city_id = null;
    }
}
