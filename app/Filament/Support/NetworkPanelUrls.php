<?php

namespace App\Filament\Support;

use App\Filament\Distributor\Pages\DistributorPos;
use App\Filament\Distributor\Resources\DistributorDistributionResource;
use App\Filament\Distributor\Resources\DistributorInvoiceResource;
use App\Filament\Distributor\Resources\DistributorRetailTraderResource;
use App\Filament\Resources\InvoiceDistributionResource;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\InvoiceResource\Pages\CreatePlumberPos;
use App\Filament\Resources\InvoiceResource\Pages\CreateRetailPos;
use App\Filament\Resources\InvoiceResource\Pages\CreateWholesaleInvoice;
use App\Filament\Resources\RetailTraderResource;
use App\Filament\Trader\Pages\TraderPos;
use App\Filament\Trader\Resources\TraderDistributionResource;
use App\Filament\Trader\Resources\TraderInvoiceResource;
use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Models\User;

class NetworkPanelUrls
{
    public static function adminResourceUrl(string $resourceClass, string $name = 'index', array $parameters = []): string
    {
        return $resourceClass::getUrl($name, $parameters, panel: 'admin');
    }

    public static function panelId(): string
    {
        $userPanel = static::panelIdForUser(auth()->user());
        $currentPanel = filament()->getCurrentPanel()?->getId();

        if ($currentPanel === null) {
            return $userPanel;
        }

        if ($userPanel !== 'admin' && $currentPanel === 'admin') {
            return $userPanel;
        }

        return $currentPanel;
    }

    public static function panelIdForUser(?User $user): string
    {
        if ($user?->isWholesaleDistributor()) {
            return 'distributor';
        }

        if ($user?->isRetailTrader()) {
            return 'trader';
        }

        return 'admin';
    }

    public static function invoiceIndex(array $parameters = []): string
    {
        return static::invoiceResource()::getUrl('index', $parameters, panel: static::panelId());
    }

    public static function invoiceView(Invoice $invoice): string
    {
        return static::invoiceResource()::getUrl('view', ['record' => $invoice], panel: static::panelId());
    }

    public static function distributionIndex(array $parameters = []): string
    {
        return static::distributionResource()::getUrl('index', $parameters, panel: static::panelId());
    }

    public static function distributionView(InvoiceDistribution $distribution): string
    {
        return static::distributionResource()::getUrl('view', ['record' => $distribution], panel: static::panelId());
    }

    public static function distributionCreate(array $parameters = []): string
    {
        return static::distributionResource()::getUrl('create', $parameters, panel: static::panelId());
    }

    public static function retailTraderIndex(): ?string
    {
        if (static::panelId() !== 'distributor') {
            return RetailTraderResource::canViewAny()
                ? RetailTraderResource::getUrl('index', panel: 'admin')
                : null;
        }

        return DistributorRetailTraderResource::canViewAny()
            ? DistributorRetailTraderResource::getUrl('index', panel: 'distributor')
            : null;
    }

    public static function retailTraderCreate(): ?string
    {
        if (static::panelId() === 'distributor') {
            return DistributorRetailTraderResource::canCreate()
                ? DistributorRetailTraderResource::getUrl('create', panel: 'distributor')
                : null;
        }

        return RetailTraderResource::canCreate()
            ? RetailTraderResource::getUrl('create', panel: 'admin')
            : null;
    }

    public static function posRetail(): ?string
    {
        if (static::panelId() === 'distributor') {
            return CreateRetailPos::canAccess()
                ? DistributorPos::getUrl(panel: 'distributor')
                : null;
        }

        return CreateRetailPos::canAccess()
            ? InvoiceResource::getUrl('pos-retail', panel: 'admin')
            : null;
    }

    public static function posPlumber(): ?string
    {
        if (static::panelId() === 'trader') {
            return CreatePlumberPos::canAccess()
                ? TraderPos::getUrl(panel: 'trader')
                : null;
        }

        return CreatePlumberPos::canAccess()
            ? InvoiceResource::getUrl('pos-plumber', panel: 'admin')
            : null;
    }

    public static function posWholesale(): ?string
    {
        return CreateWholesaleInvoice::canAccess()
            ? InvoiceResource::getUrl('pos-create', panel: 'admin')
            : null;
    }

    /** @return class-string */
    private static function invoiceResource(): string
    {
        return match (static::panelId()) {
            'distributor' => DistributorInvoiceResource::class,
            'trader' => TraderInvoiceResource::class,
            default => InvoiceResource::class,
        };
    }

    /** @return class-string */
    private static function distributionResource(): string
    {
        return match (static::panelId()) {
            'distributor' => DistributorDistributionResource::class,
            'trader' => TraderDistributionResource::class,
            default => InvoiceDistributionResource::class,
        };
    }
}
