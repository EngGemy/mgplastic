<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\SystemLabelResource\Pages;
use App\Models\SystemLabel;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;

class SystemLabelResource extends Resource
{
    use AdminOnlyResource;

    protected static ?string $model = SystemLabel::class;

    protected static ?string $navigationIcon = 'heroicon-o-language';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return 'الإعدادات';
    }

    public static function getModelLabel(): string
    {
        return 'مسمى';
    }

    public static function getPluralModelLabel(): string
    {
        return 'المسميات';
    }

    public static function getNavigationLabel(): string
    {
        return 'تخصيص المسميات';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('بيانات المسمى')
                ->schema([
                    Forms\Components\TextInput::make('key')
                        ->label('المفتاح (لا تعدّله)')
                        ->disabled()
                        ->helperText('معرّف ثابت يستخدمه النظام داخلياً'),

                    Forms\Components\TextInput::make('default_value')
                        ->label('القيمة الافتراضية')
                        ->disabled()
                        ->helperText('القيمة الأصلية — للمرجع فقط'),

                    Forms\Components\TextInput::make('custom_value')
                        ->label('المسمى المخصص')
                        ->helperText('اتركه فارغاً لاستخدام الافتراضي')
                        ->maxLength(100)
                        ->placeholder('اكتب المسمى المخصص هنا...'),

                    Forms\Components\Placeholder::make('description_display')
                        ->label('وصف الحقل')
                        ->content(fn ($record) => $record?->description ?? '—'),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('group')
                    ->label('المجموعة')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'roles' => 'الأدوار',
                        'navigation' => 'التنقل',
                        'finance' => 'المالية',
                        'quick_access' => 'الوصول السريع',
                        default => $state,
                    })
                    ->color(fn ($state) => match ($state) {
                        'roles' => 'primary',
                        'navigation' => 'info',
                        'finance' => 'success',
                        'quick_access' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('default_value')
                    ->label('الافتراضي')
                    ->color('gray'),

                Tables\Columns\TextColumn::make('custom_value')
                    ->label('المخصص')
                    ->placeholder('—')
                    ->weight('bold')
                    ->color(fn ($state) => $state ? 'success' : 'gray'),

                Tables\Columns\TextColumn::make('description')
                    ->label('الوصف')
                    ->color('gray')
                    ->wrap()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('effective_value')
                    ->label('القيمة الفعلية')
                    ->getStateUsing(fn ($record) => $record->custom_value ?: $record->default_value)
                    ->badge()
                    ->color('primary'),
            ])
            ->defaultGroup('group')
            ->groups([
                Tables\Grouping\Group::make('group')
                    ->label('المجموعة')
                    ->getTitleFromRecordUsing(fn ($record) => match ($record->group) {
                        'roles' => '👥 الأدوار',
                        'navigation' => '🧭 التنقل',
                        'finance' => '💰 المالية',
                        'quick_access' => '⚡ الوصول السريع',
                        default => $record->group,
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('group')
                    ->label('المجموعة')
                    ->options([
                        'roles' => 'الأدوار',
                        'navigation' => 'التنقل',
                        'finance' => 'المالية',
                        'quick_access' => 'الوصول السريع',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('quick_edit')
                    ->label('تعديل سريع')
                    ->icon('heroicon-o-pencil-square')
                    ->color('primary')
                    ->fillForm(fn ($record) => ['custom_value' => $record->custom_value])
                    ->form([
                        Forms\Components\TextInput::make('custom_value')
                            ->label('المسمى المخصص')
                            ->placeholder('اتركه فارغاً للافتراضي')
                            ->maxLength(100),
                    ])
                    ->action(function ($record, array $data) {
                        $record->update(['custom_value' => $data['custom_value'] ?: null]);
                        Cache::forget("system_label_{$record->key}");
                        $effective = $data['custom_value'] ?: $record->default_value;
                        Notification::make()
                            ->success()
                            ->title('تم تحديث المسمى')
                            ->body("تم تغيير «{$record->default_value}» إلى «{$effective}»")
                            ->send();
                    }),

                Tables\Actions\Action::make('reset')
                    ->label('إعادة تعيين')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->visible(fn ($record) => ! empty($record->custom_value))
                    ->requiresConfirmation()
                    ->modalHeading('إعادة تعيين المسمى')
                    ->modalDescription('سيعود المسمى إلى قيمته الافتراضية.')
                    ->action(function ($record) {
                        $record->update(['custom_value' => null]);
                        Cache::forget("system_label_{$record->key}");
                        Notification::make()
                            ->success()
                            ->title('تم إعادة التعيين')
                            ->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('bulk_reset')
                    ->label('إعادة تعيين المحدد')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->action(function ($records) {
                        $records->each(function ($record) {
                            $record->update(['custom_value' => null]);
                            Cache::forget("system_label_{$record->key}");
                        });
                        Notification::make()
                            ->success()
                            ->title('تم إعادة تعيين المسميات المحددة')
                            ->send();
                    }),
            ])
            ->emptyStateHeading('لا توجد مسميات')
            ->emptyStateDescription('شغّل: php artisan db:seed --class=SystemLabelSeeder')
            ->emptyStateIcon('heroicon-o-language');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSystemLabels::route('/'),
            'edit' => Pages\EditSystemLabel::route('/{record}/edit'),
        ];
    }
}
