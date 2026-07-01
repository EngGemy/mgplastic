<?php

namespace App\Filament\Widgets;

use App\Models\Event;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class LatestEvents extends BaseWidget
{
    protected static bool $isDiscovered = false;
    // Localized heading
    protected static ?string $heading = null;

    protected function getHeading(): ?string
    {
        return __('Upcoming Events');
    }

    public function table(Table $table): Table
    {
        $locale = app()->getLocale();

        return $table
            ->query(
                Event::query()
                    ->orderByDesc('event_date')
                    ->latest()
                    ->limit(8)
            )
            ->columns([
                Tables\Columns\TextColumn::make('event_date')
                    ->label(__('Date'))
                    ->date(),

                Tables\Columns\TextColumn::make('event_time')
                    ->label(__('Time')),

                // Category (locale-aware with fallback to EN)
                Tables\Columns\TextColumn::make('category.name')
                    ->label(__('Category'))
                    ->state(function ($record) use ($locale) {
                        $cat = $record->category;
                        if (!$cat) return null;

                        $attr = "name_{$locale}";
                        return $cat->{$attr} ?? $cat->name_en ?? null;
                    }),

                // City (locale-aware with fallback to EN)
                Tables\Columns\TextColumn::make('city.name')
                    ->label(__('City'))
                    ->state(function ($record) use ($locale) {
                        $city = $record->city;
                        if (!$city) return null;

                        $attr = "name_{$locale}";
                        return $city->{$attr} ?? $city->name_en ?? null;
                    }),

                // Title (uses model translation if available; falls back to EN)
                Tables\Columns\TextColumn::make('title')
                    ->label(__('Title'))
                    ->state(function (Event $event) use ($locale) {
                        // If using astrotomic/translatable or similar:
                        $tr = optional($event->translate($locale))->title
                            ?? optional($event->translate('en'))->title;

                        // If columns like title_ar/title_en exist, use them as a fallback:
                        if (!$tr) {
                            $attr = "title_{$locale}";
                            $tr = $event->{$attr} ?? $event->title_en ?? null;
                        }
                        return $tr;
                    })
                    ->wrap()
                    ->limit(40),
            ]);
    }
}
