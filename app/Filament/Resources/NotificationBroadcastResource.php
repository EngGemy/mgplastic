<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\NotificationBroadcastResource\Pages;
use App\Models\NotificationBroadcast;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class NotificationBroadcastResource extends Resource
{
    use AdminOnlyResource;

    protected static ?string $model = NotificationBroadcast::class;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return 'الإشعارات';
    }

    public static function getNavigationLabel(): string
    {
        return 'مكتبة الإرسال';
    }

    public static function getModelLabel(): string
    {
        return 'حملة إشعار';
    }

    public static function getPluralModelLabel(): string
    {
        return 'مكتبة الإرسال';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sent_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('title')
                    ->label('العنوان')
                    ->searchable()
                    ->weight('bold')
                    ->limit(40),
                Tables\Columns\TextColumn::make('body')
                    ->label('المحتوى')
                    ->limit(40)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('audience')
                    ->label('المستلمون')
                    ->formatStateUsing(fn (NotificationBroadcast $r) => $r->audienceLabel())
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('recipients_count')
                    ->label('العدد')
                    ->sortable()
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('status')
                    ->label('النوع')
                    ->badge()
                    ->formatStateUsing(fn (NotificationBroadcast $r) => $r->statusLabel())
                    ->color(fn (NotificationBroadcast $r) => $r->statusColor()),
                Tables\Columns\IconColumn::make('sent_push')
                    ->label('Push')
                    ->boolean(),
                Tables\Columns\TextColumn::make('sender.name')
                    ->label('المرسل')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('sent_at')
                    ->label('وقت الإرسال')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('audience')
                    ->label('الجمهور')
                    ->options([
                        'plumbers' => 'سباكون',
                        'retailers' => 'قطاعي',
                        'wholesalers' => 'جملة',
                        'role' => 'دور',
                        'users' => 'مجموعة',
                        'user' => 'فرد',
                        'all' => 'الكل',
                    ]),
                Tables\Filters\SelectFilter::make('status')
                    ->label('النوع')
                    ->options([
                        'info' => 'معلومة',
                        'success' => 'نجاح',
                        'warning' => 'تحذير',
                        'danger' => 'تنبيه',
                    ]),
                Tables\Filters\TernaryFilter::make('sent_push')->label('مع Push'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('تفاصيل'),
            ])
            ->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('الحملة')
                ->schema([
                    Infolists\Components\TextEntry::make('title')->label('العنوان'),
                    Infolists\Components\TextEntry::make('body')->label('المحتوى')->columnSpanFull(),
                    Infolists\Components\TextEntry::make('status')
                        ->label('النوع')
                        ->formatStateUsing(fn (NotificationBroadcast $r) => $r->statusLabel())
                        ->badge()
                        ->color(fn (NotificationBroadcast $r) => $r->statusColor()),
                    Infolists\Components\TextEntry::make('audience')
                        ->label('الجمهور')
                        ->formatStateUsing(fn (NotificationBroadcast $r) => $r->audienceLabel()),
                    Infolists\Components\TextEntry::make('recipients_count')->label('عدد المستلمين'),
                    Infolists\Components\IconEntry::make('sent_push')->label('أُرسل Push')->boolean(),
                    Infolists\Components\TextEntry::make('sender.name')->label('المرسل'),
                    Infolists\Components\TextEntry::make('sent_at')->label('وقت الإرسال')->dateTime('Y-m-d H:i'),
                ])
                ->columns(2),
            Infolists\Components\Section::make('فتح التطبيق')
                ->schema([
                    Infolists\Components\TextEntry::make('push_type')->label('نوع الصفحة')->placeholder('—'),
                    Infolists\Components\TextEntry::make('push_id')->label('المعرّف')->placeholder('—'),
                    Infolists\Components\TextEntry::make('url')->label('رابط ويب')->placeholder('—')->columnSpanFull(),
                    Infolists\Components\TextEntry::make('meta')
                        ->label('بيانات إضافية')
                        ->formatStateUsing(fn ($state) => $state ? json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : '—')
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->collapsed(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotificationBroadcasts::route('/'),
            'view' => Pages\ViewNotificationBroadcast::route('/{record}'),
        ];
    }
}
