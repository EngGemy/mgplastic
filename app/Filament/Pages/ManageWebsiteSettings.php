<?php

namespace App\Filament\Pages;

use App\Models\WebsiteSetting;
use App\Support\AdminPermissions;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class ManageWebsiteSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';
    protected static ?int $navigationSort = 40;
    protected static string $view = 'filament.pages.manage-website-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->isSuperAdmin()
            || $user?->canAdminPermission(AdminPermissions::USERS_MANAGE);
    }

    public static function getNavigationGroup(): ?string
    {
        return 'المحتوى';
    }

    public static function getNavigationLabel(): string
    {
        return 'إعدادات الموقع';
    }

    public function getTitle(): string
    {
        return 'إعدادات الصفحة الرئيسية';
    }

    public function mount(): void
    {
        $data = WebsiteSetting::instance()->toArray();
        $data['about_paragraphs'] = collect($data['about_paragraphs'] ?? [])
            ->map(fn ($paragraph) => ['paragraph' => $paragraph])
            ->values()
            ->all();

        $this->form->fill($data);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Tabs::make('website')
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('عام')
                            ->schema([
                                Forms\Components\TextInput::make('site_name')->label('اسم الموقع')->required(),
                                Forms\Components\TextInput::make('site_domain')->label('النطاق')->required(),
                                Forms\Components\TextInput::make('seo_title')->label('عنوان SEO')->columnSpanFull(),
                                Forms\Components\Textarea::make('seo_description')->label('وصف SEO')->rows(3)->columnSpanFull(),
                                Forms\Components\Textarea::make('footer_tagline')->label('وصف الفوتر')->rows(2)->columnSpanFull(),
                                Forms\Components\FileUpload::make('catalog_pdf')->label('كتالوج PDF')->disk('public')->directory('website')->acceptedFileTypes(['application/pdf']),
                            ])->columns(2),

                        Forms\Components\Tabs\Tab::make('عن الشركة')
                            ->schema([
                                Forms\Components\TextInput::make('about_eyebrow')->label('العنوان الفرعي'),
                                Forms\Components\TextInput::make('about_title')->label('العنوان الرئيسي'),
                                Forms\Components\TextInput::make('about_subtitle')->label('سطر تقني'),
                                Forms\Components\Repeater::make('about_paragraphs')->label('الفقرات')->simple(
                                    Forms\Components\Textarea::make('paragraph')->label('فقرة')->rows(3)
                                )->columnSpanFull(),
                                Forms\Components\FileUpload::make('about_image')->label('صورة القسم')->disk('public')->directory('website')->image(),
                                Forms\Components\TextInput::make('about_badge_year')->label('سنة التأسيس'),
                                Forms\Components\TextInput::make('about_badge_text')->label('نص الشارة'),
                                Forms\Components\Repeater::make('about_values')->label('القيم')->schema([
                                    Forms\Components\TextInput::make('icon')->label('أيقونة')->default('ti-star'),
                                    Forms\Components\TextInput::make('title')->label('العنوان')->required(),
                                    Forms\Components\TextInput::make('desc')->label('الوصف'),
                                ])->columns(3)->columnSpanFull(),
                            ])->columns(2),

                        Forms\Components\Tabs\Tab::make('التواصل')
                            ->schema([
                                Forms\Components\TextInput::make('contact_phone')->label('الهاتف'),
                                Forms\Components\TextInput::make('contact_whatsapp')->label('واتساب'),
                                Forms\Components\TextInput::make('contact_email')->label('البريد')->email(),
                                Forms\Components\TextInput::make('contact_address')->label('العنوان'),
                                Forms\Components\TextInput::make('contact_address_detail')->label('تفاصيل العنوان'),
                                Forms\Components\TextInput::make('contact_work_days')->label('أيام العمل'),
                                Forms\Components\TextInput::make('contact_work_hours')->label('ساعات العمل'),
                                Forms\Components\TextInput::make('map_latitude')->label('خط العرض')->numeric(),
                                Forms\Components\TextInput::make('map_longitude')->label('خط الطول')->numeric(),
                            ])->columns(2),

                        Forms\Components\Tabs\Tab::make('نظام النقاط')
                            ->schema([
                                Forms\Components\TextInput::make('points_eyebrow')->label('العنوان الفرعي'),
                                Forms\Components\TextInput::make('points_title')->label('العنوان'),
                                Forms\Components\TextInput::make('points_subtitle')->label('سطر تقني'),
                                Forms\Components\Repeater::make('points_chain')->label('سلسلة التوزيع')->schema([
                                    Forms\Components\TextInput::make('title')->label('المرحلة')->required(),
                                    Forms\Components\TextInput::make('subtitle')->label('الوصف'),
                                    Forms\Components\ColorPicker::make('color')->label('اللون'),
                                ])->columns(3)->columnSpanFull(),
                                Forms\Components\Repeater::make('points_features')->label('المميزات')->schema([
                                    Forms\Components\TextInput::make('title')->label('العنوان')->required(),
                                    Forms\Components\TextInput::make('desc')->label('الوصف'),
                                ])->columns(2)->columnSpanFull(),
                            ])->columns(2),
                    ])
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $settings = WebsiteSetting::instance();

        if (isset($data['about_paragraphs']) && is_array($data['about_paragraphs'])) {
            $data['about_paragraphs'] = collect($data['about_paragraphs'])
                ->map(fn ($item) => is_array($item) ? ($item['paragraph'] ?? '') : $item)
                ->filter()
                ->values()
                ->all();
        }

        $settings->update($data);

        Notification::make()->title('تم حفظ إعدادات الموقع')->success()->send();
    }
}
