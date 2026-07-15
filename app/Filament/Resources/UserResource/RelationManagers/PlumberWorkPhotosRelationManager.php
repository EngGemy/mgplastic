<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Models\PlumberWorkPhoto;
use App\Models\User;
use App\Services\VideoThumbnailService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class PlumberWorkPhotosRelationManager extends RelationManager
{
    protected static string $relationship = 'plumberWorkPhotos';
    protected static ?string $recordTitleAttribute = 'id';
    protected static ?string $title = 'الأعمال (صور وفيديو)';
    protected static ?string $icon = 'heroicon-o-photo';

    /**
     * Show this relation tab only for plumbers.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->role === User::ROLE_PLUMBER;
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\FileUpload::make('upload')
                ->label('صورة أو فيديو')
                ->disk('public')
                ->directory('work_photos/uploads')
                ->acceptedFileTypes([
                    'image/jpeg', 'image/png', 'image/webp',
                    'video/mp4', 'video/quicktime', 'video/webm', 'video/x-matroska',
                ])
                ->maxSize(512000)
                ->downloadable()
                ->openable()
                ->required()
                ->helperText('الصور تظهر مباشرة، والفيديو يُنشأ له غلاف (thumbnail) تلقائياً.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\ImageColumn::make('thumbnail_url')
                    ->label('المعاينة')
                    ->height(64)
                    ->square()
                    ->extraImgAttributes(['style' => 'object-fit:cover;border-radius:10px']),

                Tables\Columns\TextColumn::make('type')
                    ->label('النوع')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'video' ? 'فيديو' : 'صورة')
                    ->color(fn (string $state) => $state === 'video' ? 'warning' : 'info')
                    ->icon(fn (string $state) => $state === 'video' ? 'heroicon-o-play-circle' : 'heroicon-o-photo'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('أُضيف')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('إضافة عمل')
                    ->using(fn (array $data): Model => $this->storeMedia($data)),
            ])
            ->actions([
                Tables\Actions\Action::make('preview')
                    ->label('معاينة')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading('معاينة العمل')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('إغلاق')
                    ->modalContent(fn (PlumberWorkPhoto $record) => view(
                        'filament.modals.plumber-work-preview',
                        ['record' => $record],
                    )),

                Tables\Actions\DeleteAction::make()
                    ->before(fn (PlumberWorkPhoto $record) => $this->deleteFiles($record)),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->before(fn ($records) => $records->each(fn ($r) => $this->deleteFiles($r))),
                ]),
            ])
            ->emptyStateHeading('لا توجد أعمال بعد')
            ->emptyStateDescription('يرفع السباك أعماله من التطبيق، أو أضِفها يدوياً من هنا.')
            ->emptyStateIcon('heroicon-o-photo');
    }

    /** @param  array<string, mixed>  $data */
    protected function storeMedia(array $data): Model
    {
        $path = $data['upload'] ?? null;
        $path = is_array($path) ? (string) reset($path) : (string) $path;

        $owner = $this->getOwnerRecord();
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $isVideo = in_array($ext, ['mp4', 'mov', 'webm', 'mkv', 'avi'], true);

        if ($isVideo && Schema::hasColumn((new PlumberWorkPhoto)->getTable(), 'video_path')) {
            $thumb = app(VideoThumbnailService::class)->generate($path, 'work_photos/thumbnails');

            return $owner->plumberWorkPhotos()->create([
                'video_path' => $path,
                'image'      => $thumb,
            ]);
        }

        return $owner->plumberWorkPhotos()->create([
            'image' => $path,
        ]);
    }

    protected function deleteFiles(PlumberWorkPhoto $record): void
    {
        foreach ([$record->image, $record->video_path] as $file) {
            if (! empty($file) && Storage::disk('public')->exists($file)) {
                Storage::disk('public')->delete($file);
            }
        }
    }
}
