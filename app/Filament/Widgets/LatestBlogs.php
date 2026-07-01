<?php

namespace App\Filament\Widgets;

use App\Models\Blog;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class LatestBlogs extends BaseWidget
{
    protected static bool $isDiscovered = false;
    // Use a method so it’s always run through the translator
    protected static ?string $heading = null;

    public function getHeading(): ?string
    {
        return __('Latest Blogs');
    }

 


    public function table(Table $table): Table
    {
        $locale = app()->getLocale();

        return $table
            ->query(Blog::query()->latest()->limit(8))
            ->columns([
                // Title (locale-aware if you have title_en/title_ar; fallback to EN)
                Tables\Columns\TextColumn::make('title')
                    ->label(__('Title'))
                    ->state(function (Blog $blog) use ($locale) {
                        $attr = "title_{$locale}";
                        return $blog->{$attr} ?? $blog->title_en ?? $blog->title;
                    })
                    ->wrap()
                    ->limit(40),

                // Author
                Tables\Columns\TextColumn::make('author.name')
                    ->label(__('Author')),

                // Status with translation + badge
                Tables\Columns\TextColumn::make('status')
                    ->label(__('Status'))
                    ->formatStateUsing(function (?string $state) {
                        // Expecting values like: draft, published, archived (adjust as needed)
                        return match ($state) {
                            'draft'     => __('Draft'),
                            'published' => __('Published'),
                            'archived'  => __('Archived'),
                            default     => __($state ?? '—'),
                        };
                    })
                    ->badge(),

                // Created at (human diff) with label
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('Created'))
                    ->since(),
            ]);
    }
}
