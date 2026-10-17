<?php

namespace App\Filament\Warehouse\Pages;

use App\Models\User;
use App\Services\NetworkReportService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

class WarehouseReports extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationLabel = 'التقارير';

    protected static ?string $title = 'تقارير مخزن الدعوة';

    protected static ?string $navigationGroup = 'لوحة المخزن';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.warehouse.pages.reports';

    /** @var array{from:?string, to:?string, warehouse_id:?int} */
    public array $data = [];

    /** @var array<string, mixed> */
    public array $report = [];

    /** @var list<array<string, mixed>> */
    public array $networkWarehouses = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isCentralWarehouse();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        $this->data = [
            'from' => request()->query('from', now()->subDays(29)->toDateString()),
            'to' => request()->query('to', now()->toDateString()),
            'warehouse_id' => request()->integer('warehouse_id') ?: null,
        ];

        $this->form->fill($this->data);
        $this->loadReport();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\DatePicker::make('from')
                    ->label('من')
                    ->native(false)
                    ->displayFormat('Y/m/d')
                    ->required(),
                Forms\Components\DatePicker::make('to')
                    ->label('إلى')
                    ->native(false)
                    ->displayFormat('Y/m/d')
                    ->required(),
                Forms\Components\Select::make('warehouse_id')
                    ->label('تقرير لمخزن معيّن')
                    ->placeholder('— تقرير مخزن الدعوة (الرئيسي) —')
                    ->options(fn () => User::query()
                        ->where('role', 'wholesale_distributor')
                        ->where(function ($q) {
                            $q->where('is_central_warehouse', false)->orWhereNull('is_central_warehouse');
                        })
                        ->whereKeyNot(auth()->id() ?? 0)
                        ->orderBy('brand_name')
                        ->get()
                        ->mapWithKeys(fn (User $u) => [
                            $u->id => ($u->brand_name ?: $u->name).($u->network_code ? " — {$u->network_code}" : ''),
                        ])
                        ->all())
                    ->searchable()
                    ->nullable(),
            ])
            ->columns(3)
            ->statePath('data');
    }

    public function applyFilters(): void
    {
        $this->data = $this->form->getState();
        $this->loadReport();
    }

    protected function loadReport(): void
    {
        $service = app(NetworkReportService::class);
        $from = $this->data['from'] ?? now()->subDays(29)->toDateString();
        $to = $this->data['to'] ?? now()->toDateString();
        $warehouseId = ! empty($this->data['warehouse_id']) ? (int) $this->data['warehouse_id'] : null;

        if ($warehouseId) {
            $target = User::query()->find($warehouseId);
            $this->report = $target ? $service->forUser($target, $from, $to) : [];
            $this->networkWarehouses = [];

            return;
        }

        $me = auth()->user();
        $this->report = $service->forUser($me, $from, $to);
        $this->networkWarehouses = $service->networkWholesalerSummaries($me, $from, $to);
    }

    public function getHeading(): string
    {
        $warehouseId = ! empty($this->data['warehouse_id']) ? (int) $this->data['warehouse_id'] : null;
        if ($warehouseId) {
            $u = User::query()->find($warehouseId);

            return 'تقرير: '.($u?->brand_name ?: $u?->name ?: 'مخزن');
        }

        return 'تقارير مخزن الدعوة — نظرة شاملة';
    }

    public function getSubheading(): ?string
    {
        return 'من '.($this->data['from'] ?? '—').' إلى '.($this->data['to'] ?? '—');
    }
}
