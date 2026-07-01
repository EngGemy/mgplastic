<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\SliderResource\Pages;
use App\Models\Slider;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Filters\SelectFilter;

class SliderResource extends Resource
{
    use AdminOnlyResource;
    protected static ?string $model = Slider::class;
    protected static ?string $navigationIcon  = 'heroicon-o-photo';
    protected static ?int $navigationSort = 50;

    public static function getNavigationGroup(): ?string { return 'المحتوى'; }
    public static function getNavigationLabel(): string { return 'الشرائح (Sliders)'; }
    public static function getModelLabel(): string { return 'شريحة'; }
    public static function getPluralModelLabel(): string { return 'الشرائح'; }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('type')
                ->label(__('Type'))
                ->options([
                    'home'  => __('Home'),
                    'store' => __('Store'),
                ])
                ->native(false)
                ->required()
                ->default('home'),

            Forms\Components\TextInput::make('tag')->label('الوسم')->maxLength(255),
            Forms\Components\TextInput::make('title')->label('العنوان')->maxLength(255)->columnSpanFull(),
            Forms\Components\Textarea::make('description')->label('الوصف')->rows(3)->columnSpanFull(),

            Forms\Components\TextInput::make('cta_primary_text')->label('زر أساسي — نص'),
            Forms\Components\TextInput::make('cta_primary_url')->label('زر أساسي — رابط'),
            Forms\Components\TextInput::make('cta_secondary_text')->label('زر ثانوي — نص'),
            Forms\Components\TextInput::make('cta_secondary_url')->label('زر ثانوي — رابط'),

            Forms\Components\TextInput::make('background_style')
                ->label('خلفية CSS (بدون صورة)')
                ->placeholder('background:linear-gradient(135deg,#0d2d6e,#1a56db)')
                ->columnSpanFull(),

            Forms\Components\FileUpload::make('image')
                ->label(__('Image'))
                ->disk('public')
                ->directory('sliders')
                ->image()
                ->imageEditor()
                ->openable()
                ->downloadable()
                ->columnSpanFull(),

            Forms\Components\TextInput::make('sort_order')->label('الترتيب')->numeric()->default(0),
            Forms\Components\Toggle::make('is_active')->label('نشط')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image')
                    ->label(__('Image'))
                    ->disk('public')
                    ->square()
                    ->size(96),

                Tables\Columns\TextColumn::make('type')
                    ->label(__('Type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'store' ? __('Store') : __('Home'))
                    ->color(fn (string $state) => $state === 'store' ? 'warning' : 'success')
                    ->sortable(),

                Tables\Columns\TextColumn::make('title')
                    ->label('العنوان')
                    ->limit(40)
                    ->searchable(),

                Tables\Columns\TextColumn::make('sort_order')
                    ->label('الترتيب')
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('Created at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('Updated at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('Type'))
                    ->options([
                        'home'  => __('Home'),
                        'store' => __('Store'),
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSliders::route('/'),
            'create' => Pages\CreateSlider::route('/create'),
            'edit'   => Pages\EditSlider::route('/{record}/edit'),
        ];
    }
}
