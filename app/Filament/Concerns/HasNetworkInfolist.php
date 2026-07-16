<?php

namespace App\Filament\Concerns;

use App\Models\User;
use Filament\Infolists;

trait HasNetworkInfolist
{
    protected static function networkProfileHeaderEntry(): Infolists\Components\ViewEntry
    {
        return Infolists\Components\ViewEntry::make('profile_header')
            ->view('filament.infolists.network-profile-header')
            ->columnSpanFull();
    }

    protected static function networkContactSection(): Infolists\Components\Section
    {
        return Infolists\Components\Section::make('معلومات التواصل والموقع')
            ->icon('heroicon-o-phone')
            ->schema([
                Infolists\Components\Grid::make(3)->schema([
                    Infolists\Components\TextEntry::make('network_code')
                        ->label('الرقم الموحّد')
                        ->icon('heroicon-o-qr-code')
                        ->copyable()
                        ->copyMessage('تم نسخ الرقم الموحّد')
                        ->copyMessageDuration(1500)
                        ->weight('bold')
                        ->color('primary')
                        ->visible(fn (User $r) => filled($r->network_code)),

                    Infolists\Components\TextEntry::make('phone')
                        ->label('الهاتف')
                        ->icon('heroicon-o-phone')
                        ->copyable()
                        ->copyMessage('تم نسخ رقم الهاتف')
                        ->weight('semibold'),

                    Infolists\Components\TextEntry::make('email')
                        ->label('البريد الإلكتروني')
                        ->icon('heroicon-o-envelope')
                        ->default('—')
                        ->copyable()
                        ->copyMessage('تم نسخ البريد'),

                    Infolists\Components\TextEntry::make('city.name_ar')
                        ->label('المدينة')
                        ->icon('heroicon-o-map-pin')
                        ->default('—'),

                    Infolists\Components\TextEntry::make('country.name_ar')
                        ->label('الدولة')
                        ->default('—'),

                    Infolists\Components\TextEntry::make('address')
                        ->label('العنوان التفصيلي')
                        ->icon('heroicon-o-home')
                        ->default('—')
                        ->columnSpan(2),

                    Infolists\Components\TextEntry::make('store_description')
                        ->label('ملاحظات')
                        ->default('—')
                        ->columnSpanFull()
                        ->visible(fn (User $r) => filled($r->store_description)),

                    Infolists\Components\TextEntry::make('short_description')
                        ->label('وصف مختصر')
                        ->default('—')
                        ->columnSpanFull()
                        ->visible(fn (User $r) => filled($r->short_description)),

                    Infolists\Components\TextEntry::make('long_description')
                        ->label('نبذة تفصيلية')
                        ->default('—')
                        ->columnSpanFull()
                        ->visible(fn (User $r) => filled($r->long_description)),
                ]),
            ]);
    }

    protected static function networkCatalogSection(): Infolists\Components\Section
    {
        return Infolists\Components\Section::make('كتالوج المتجر')
            ->icon('heroicon-o-photo')
            ->schema([
                Infolists\Components\ViewEntry::make('store_catalog')
                    ->view('filament.infolists.network-store-catalog')
                    ->columnSpanFull(),
            ])
            ->visible(fn (User $r) => $r->storeMedia()->exists());
    }

    protected static function networkSocialSection(): Infolists\Components\Section
    {
        return Infolists\Components\Section::make('روابط التواصل')
            ->icon('heroicon-o-share')
            ->schema([
                Infolists\Components\RepeatableEntry::make('socialLinks')
                    ->label('')
                    ->schema([
                        Infolists\Components\TextEntry::make('platform')
                            ->label('المنصة')
                            ->formatStateUsing(fn ($state) => \App\Models\SocialLink::PLATFORMS[$state] ?? $state)
                            ->badge(),

                        Infolists\Components\TextEntry::make('url')
                            ->label('الرابط')
                            ->url(fn ($state) => $state, true)
                            ->openUrlInNewTab()
                            ->color('primary'),
                    ])
                    ->columns(2),
            ])
            ->visible(fn (User $r) => $r->socialLinks()->exists());
    }

    protected static function networkMapSection(): Infolists\Components\Section
    {
        return Infolists\Components\Section::make('الموقع على الخريطة')
            ->icon('heroicon-o-map')
            ->schema([
                Infolists\Components\ViewEntry::make('map')
                    ->view('filament.infolists.osm-map-display'),
            ])
            ->visible(fn (User $r) => $r->hasMapLocation());
    }

    protected static function networkStatusSection(): Infolists\Components\Section
    {
        return Infolists\Components\Section::make('حالة الحساب')
            ->icon('heroicon-o-shield-check')
            ->schema([
                Infolists\Components\Grid::make(4)->schema([
                    Infolists\Components\IconEntry::make('is_active')
                        ->label('نشط')
                        ->boolean(),

                    Infolists\Components\IconEntry::make('is_approved')
                        ->label('معتمد')
                        ->boolean(),

                    Infolists\Components\IconEntry::make('is_phone_verified')
                        ->label('هاتف موثّق')
                        ->boolean(),

                    Infolists\Components\TextEntry::make('created_at')
                        ->label('تاريخ الإنشاء')
                        ->dateTime('Y/m/d'),
                ]),
            ])
            ->collapsed();
    }
}
