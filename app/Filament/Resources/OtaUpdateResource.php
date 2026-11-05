<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\OtaUpdateResource\Pages;
use App\Models\OtaUpdate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class OtaUpdateResource extends Resource
{
    use AdminOnlyResource;

    protected static ?string $model = OtaUpdate::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?int $navigationSort = 95;

    public static function getNavigationGroup(): ?string
    {
        return 'النظام';
    }

    public static function getNavigationLabel(): string
    {
        return 'تحديثات OTA';
    }

    public static function getModelLabel(): string
    {
        return 'تحديث OTA';
    }

    public static function getPluralModelLabel(): string
    {
        return 'تحديثات OTA';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Group::make()
                    ->schema([
                        Forms\Components\Section::make('ملف التحديث')
                            ->description('كل zip مبني لنسخة native محددة. التطابق يجب أن يكون تاماً.')
                            ->icon('heroicon-o-archive-box')
                            ->schema([
                                Forms\Components\ToggleButtons::make('platform')
                                    ->label('المنصة')
                                    ->options([
                                        OtaUpdate::PLATFORM_IOS => 'iOS',
                                        OtaUpdate::PLATFORM_ANDROID => 'Android',
                                    ])
                                    ->icons([
                                        OtaUpdate::PLATFORM_IOS => 'heroicon-o-device-phone-mobile',
                                        OtaUpdate::PLATFORM_ANDROID => 'heroicon-o-device-phone-mobile',
                                    ])
                                    ->inline()
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(fn (Get $get, Set $set) => self::suggestNextVersion($get, $set))
                                    ->columnSpanFull(),

                                Forms\Components\TextInput::make('native_build')
                                    ->label('رقم البناء (native_build)')
                                    ->helperText('CFBundleVersion في iOS أو versionCode في Android — تطابق تام.')
                                    ->required()
                                    ->maxLength(64)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (Get $get, Set $set) => self::suggestNextVersion($get, $set))
                                    ->columnSpan(6),

                                Forms\Components\TextInput::make('version')
                                    ->label('رقم التحديث (version)')
                                    ->helperText('يجب أن يكون أكبر من كل الأرقام الموجودة لنفس المنصة ورقم البناء.')
                                    ->required()
                                    ->numeric()
                                    ->integer()
                                    ->minValue(1)
                                    ->columnSpan(6),

                                Forms\Components\FileUpload::make('file_path')
                                    ->label('ملف ZIP')
                                    ->disk('public')
                                    ->directory('ota')
                                    ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed', 'application/octet-stream'])
                                    ->maxSize(51200) // 50 MB
                                    ->required()
                                    ->downloadable()
                                    ->openable()
                                    ->helperText('صيغة .zip فقط — حد أقصى 50MB. لا تغيّر محتوى ملف منشور.')
                                    ->getUploadedFileNameForStorageUsing(function ($file, Get $get): string {
                                        $platform = (string) ($get('platform') ?: 'ios');
                                        $build = (string) ($get('native_build') ?: '0');
                                        $version = (int) ($get('version') ?: 1);

                                        return OtaUpdate::suggestedFileName($platform, $build, $version);
                                    })
                                    ->columnSpanFull(),

                                Forms\Components\Textarea::make('release_note')
                                    ->label('ملاحظة داخلية (للفريق فقط)')
                                    ->rows(3)
                                    ->helperText('لا تظهر للمستخدم في التطبيق.')
                                    ->columnSpanFull(),
                            ])
                            ->columns(12),
                    ])
                    ->columnSpan(['lg' => 2]),

                Forms\Components\Group::make()
                    ->schema([
                        Forms\Components\Section::make('النشر')
                            ->icon('heroicon-o-rocket-launch')
                            ->schema([
                                Forms\Components\Toggle::make('is_active')
                                    ->label('مفعّل للتطبيق')
                                    ->helperText('يبقى غير مفعّل حتى تختبره ثم تفعّله يدوياً. التفعيل يشغّل الكود على هواتف المستخدمين.')
                                    ->default(false)
                                    ->inline(false),
                            ]),

                        Forms\Components\Section::make('للتطبيق')
                            ->icon('heroicon-o-device-phone-mobile')
                            ->schema([
                                Forms\Components\Placeholder::make('api_hint')
                                    ->label('')
                                    ->content('GET /api/v1/mobile/ota/check?platform=ios&native_build=10&bundle_version=0'),
                            ]),
                    ])
                    ->columnSpan(['lg' => 1]),
            ])
            ->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('platform')
                    ->label('المنصة')
                    ->badge()
                    ->color(fn (string $state) => $state === OtaUpdate::PLATFORM_IOS ? 'info' : 'success')
                    ->formatStateUsing(fn (string $state) => strtoupper($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('native_build')
                    ->label('البناء')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('version')
                    ->label('الإصدار')
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('file_path')
                    ->label('الملف')
                    ->limit(40)
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('مفعّل')
                    ->boolean(),

                Tables\Columns\TextColumn::make('uploader.name')
                    ->label('رفع بواسطة')
                    ->toggleable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('activator.name')
                    ->label('فعّل بواسطة')
                    ->toggleable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الرفع')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('platform')
                    ->label('المنصة')
                    ->options([
                        OtaUpdate::PLATFORM_IOS => 'iOS',
                        OtaUpdate::PLATFORM_ANDROID => 'Android',
                    ]),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('مفعّل'),
            ])
            ->actions([
                Tables\Actions\Action::make('toggle_active')
                    ->label(fn (OtaUpdate $record) => $record->is_active ? 'إيقاف' : 'تفعيل')
                    ->icon(fn (OtaUpdate $record) => $record->is_active ? 'heroicon-o-pause-circle' : 'heroicon-o-play-circle')
                    ->color(fn (OtaUpdate $record) => $record->is_active ? 'warning' : 'success')
                    ->requiresConfirmation()
                    ->modalHeading(fn (OtaUpdate $record) => $record->is_active
                        ? 'إيقاف التحديث؟'
                        : 'تفعيل التحديث؟')
                    ->modalDescription(fn (OtaUpdate $record) => $record->is_active
                        ? 'سيُمنع وصوله لمستخدمين جدد فقط. من نزّله سابقاً يبقى عليه.'
                        : 'سيصل هذا الملف إلى كل من يفتح التطبيق بنفس المنصة ورقم البناء.')
                    ->action(function (OtaUpdate $record): void {
                        $activating = ! $record->is_active;
                        $record->is_active = $activating;
                        if ($activating) {
                            $record->activated_by = auth()->id();
                            $record->activated_at = now();
                        }
                        $record->save();

                        app(\App\Services\SecurityAuditService::class)->record(
                            $activating ? 'ota.activated' : 'ota.deactivated',
                            auth()->user(),
                            $record,
                            [
                                'platform' => $record->platform,
                                'native_build' => $record->native_build,
                                'version' => $record->version,
                            ]
                        );

                        self::forgetOtaCache();
                    }),
                Tables\Actions\EditAction::make()->label('تعديل'),
                Tables\Actions\DeleteAction::make()->label('حذف'),
            ])
            ->bulkActions([])
            ->emptyStateHeading('لا توجد تحديثات OTA بعد')
            ->emptyStateDescription('ارفع ملف zip من فريق التطبيق بعد yarn ota:release.')
            ->emptyStateIcon('heroicon-o-arrow-path');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOtaUpdates::route('/'),
            'create' => Pages\CreateOtaUpdate::route('/create'),
            'edit' => Pages\EditOtaUpdate::route('/{record}/edit'),
        ];
    }

    public static function suggestNextVersion(Get $get, Set $set): void
    {
        $platform = $get('platform');
        $build = $get('native_build');

        if (! filled($platform) || ! filled($build)) {
            return;
        }

        $set('version', OtaUpdate::nextVersionFor((string) $platform, (string) $build));
    }

    /**
     * On create: version must be strictly greater than every existing row for the same platform+build.
     * On edit: keeping the same version is allowed; changing it still must beat every other row.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function assertVersionIsNext(array $data, ?OtaUpdate $existing = null): array
    {
        $platform = (string) ($data['platform'] ?? '');
        $build = (string) ($data['native_build'] ?? '');
        $version = (int) ($data['version'] ?? 0);

        if ($existing
            && $existing->platform === $platform
            && $existing->native_build === $build
            && (int) $existing->version === $version
        ) {
            return $data;
        }

        $maxQuery = OtaUpdate::query()
            ->where('platform', $platform)
            ->where('native_build', $build);

        if ($existing) {
            $maxQuery->where('id', '!=', $existing->id);
        }

        $max = (int) $maxQuery->max('version');

        if ($version <= $max) {
            throw ValidationException::withMessages([
                'version' => "يجب أن يكون رقم التحديث أكبر من {$max} لنفس المنصة ورقم البناء.",
            ]);
        }

        return $data;
    }

    public static function forgetOtaCache(): void
    {
        OtaUpdate::bumpCheckCache();
    }
}
