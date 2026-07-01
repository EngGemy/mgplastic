<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\BlogResource\Pages;
use App\Filament\Resources\BlogResource\RelationManagers\CommentsRelationManager;
use App\Filament\Resources\BlogResource\RelationManagers\LikesRelationManager;
use App\Models\Blog;
use App\Models\BlogCategory;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BlogResource extends Resource
{
    use AdminOnlyResource;
    protected static ?string $model = Blog::class;
    protected static ?string $navigationIcon = 'heroicon-o-newspaper';
    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string { return 'المحتوى'; }
    public static function getNavigationLabel(): string { return 'المقالات'; }
    public static function getModelLabel(): string { return 'مقال'; }
    public static function getPluralModelLabel(): string { return 'المقالات'; }

    /** ✅ Base query with relations & counts (safe place for withCount) */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['category:id,name', 'author:id,name'])
            ->withCount(['likes', 'comments']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('Post'))
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('category_id')
                        ->label(__('Category'))
                        ->options(fn () => BlogCategory::query()->orderBy('name')->pluck('name', 'id')->toArray())
                        ->searchable()
                        ->preload()
                        ->required(),

                    Forms\Components\Select::make('user_id')
                        ->label(__('Author'))
                        ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id')->toArray())
                        ->searchable()
                        ->preload()
                        ->required(),

                    Forms\Components\TextInput::make('title')
                        ->label(__('Title'))
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),

                    Forms\Components\RichEditor::make('description')
                        ->label(__('Content'))
                        ->columnSpanFull()
                        ->required(),

                    Forms\Components\FileUpload::make('image')
                        ->label(__('Cover Image'))
                        ->disk('public')
                        ->directory('blogs')
                        ->image()
                        ->imageEditor()
                        ->openable()
                        ->downloadable()
                        ->columnSpanFull(),

                    Forms\Components\Select::make('status')
                        ->label(__('Status'))
                        ->options([
                            'pending'  => __('Pending'),
                            'approved' => __('Approved'),
                        ])
                        ->default('pending')
                        ->required(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // ❌ remove modifyQueryUsing() entirely
            ->columns([
                Tables\Columns\ImageColumn::make('image')
                    ->disk('public')->label(__('Cover'))->square()->size(56),

                Tables\Columns\TextColumn::make('title')
                    ->label(__('Title'))->searchable()->wrap()->limit(60)->sortable(),

                Tables\Columns\TextColumn::make('category.name')
                    ->label(__('Category'))->sortable()->searchable(),

                Tables\Columns\TextColumn::make('author.name')
                    ->label(__('Author'))->sortable()->searchable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label(__('Status'))
                    ->colors([
                        'success' => 'approved',
                        'warning' => 'pending',
                        'secondary' => fn ($state) => ! in_array($state, ['approved','pending'], true),
                    ])
                    ->formatStateUsing(fn (string $state) => __(
                        match ($state) {
                            'approved' => 'Approved',
                            'pending'  => 'Pending',
                            default    => ucfirst($state),
                        }
                    ))
                    ->sortable(),

                // ✅ use the withCount fields; no $record->likes() calls anywhere
                Tables\Columns\TextColumn::make('likes_count')->label(__('Likes'))->sortable(),
                Tables\Columns\TextColumn::make('comments_count')->label(__('Comments'))->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('Created at'))->dateTime()->since()->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category_id')
                    ->label(__('Category'))
                    ->options(fn () => BlogCategory::query()->orderBy('name')->pluck('name', 'id')->toArray())
                    ->searchable(),

                Tables\Filters\SelectFilter::make('user_id')
                    ->label(__('Author'))
                    ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id')->toArray())
                    ->searchable(),

                Tables\Filters\SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options([
                        'pending'  => __('Pending'),
                        'approved' => __('Approved'),
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label(__('Approve'))
                    ->visible(fn (Blog $r) => $r->status !== 'approved')
                    ->icon('heroicon-o-check-circle')->color('success')
                    ->requiresConfirmation()
                    ->action(fn (Blog $r) => $r->update(['status' => 'approved'])),

                Tables\Actions\Action::make('markPending')
                    ->label(__('Mark Pending'))
                    ->visible(fn (Blog $r) => $r->status !== 'pending')
                    ->icon('heroicon-o-clock')->color('warning')
                    ->requiresConfirmation()
                    ->action(fn (Blog $r) => $r->update(['status' => 'pending'])),

                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            CommentsRelationManager::class,
            LikesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListBlogs::route('/'),
            'create' => Pages\CreateBlog::route('/create'),
            'edit'   => Pages\EditBlog::route('/{record}/edit'),
        ];
    }
}
