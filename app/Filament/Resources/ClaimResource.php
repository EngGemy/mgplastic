<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\ClaimResource\Pages;
use App\Models\Claim;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ClaimResource extends Resource
{
    use AdminOnlyResource;

    protected static ?string $model = Claim::class;
    protected static ?string $navigationIcon = 'heroicon-o-exclamation-circle';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return 'المستخدمون';
    }

    public static function getNavigationLabel(): string
    {
        return __('Claims');
    }

    public static function getModelLabel(): string
    {
        return __('Claim');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Claims');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label(__('Name'))->disabled(),
            Forms\Components\TextInput::make('email')->label(__('Email'))->disabled(),
            Forms\Components\TextInput::make('phone')->label(__('Phone'))->disabled(),
            Forms\Components\Textarea::make('message')->label(__('Message'))->disabled()->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('id')->label(__('ID'))->sortable(),
            Tables\Columns\TextColumn::make('name')->label(__('Name'))->searchable()->sortable(),
            Tables\Columns\TextColumn::make('email')->label(__('Email'))->searchable(),
            Tables\Columns\TextColumn::make('phone')->label(__('Phone'))->searchable(),
            Tables\Columns\TextColumn::make('created_at')->label(__('Submitted at'))->dateTime()->sortable(),
        ])
            ->filters([])
            ->actions([
                Tables\Actions\ViewAction::make()->label(__('View')),
                Tables\Actions\DeleteAction::make()->label(__('Delete')),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make()->label(__('Delete Selected')),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\ViewEntry::make('claim_header')
                ->view('filament.infolists.claim-profile-header')
                ->columnSpanFull(),

            Infolists\Components\Section::make('بيانات المرسل')
                ->icon('heroicon-o-user')
                ->schema([
                    Infolists\Components\Grid::make(3)->schema([
                        Infolists\Components\TextEntry::make('name')
                            ->label('الاسم')
                            ->weight('bold'),

                        Infolists\Components\TextEntry::make('email')
                            ->label('البريد الإلكتروني')
                            ->icon('heroicon-o-envelope')
                            ->copyable(),

                        Infolists\Components\TextEntry::make('phone')
                            ->label('الهاتف')
                            ->icon('heroicon-o-phone')
                            ->default('—')
                            ->copyable(),
                    ]),
                ]),

            Infolists\Components\Section::make('نص الرسالة')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->schema([
                    Infolists\Components\TextEntry::make('message')
                        ->label('')
                        ->prose()
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClaims::route('/'),
            'view'  => Pages\ViewClaim::route('/{record}'),
        ];
    }
}
