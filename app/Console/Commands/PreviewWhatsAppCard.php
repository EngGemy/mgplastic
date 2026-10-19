<?php

namespace App\Console\Commands;

use App\Services\WhatsAppBrandCard;
use Illuminate\Console\Command;

class PreviewWhatsAppCard extends Command
{
    protected $signature = 'whatsapp:preview
                            {event? : Theme key (e.g. invoice_created)}
                            {--all : Generate every theme}';

    protected $description = 'Render themed WhatsApp brand cards to storage/app/whatsapp-previews for visual QA';

    public function handle(WhatsAppBrandCard $cards): int
    {
        $dir = storage_path('app/whatsapp-previews');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $themes = array_keys((array) config('whatsapp_cards.themes', []));

        if ($this->option('all')) {
            foreach ($themes as $key) {
                $this->renderOne($cards, $dir, $key);
            }
            $this->info("Wrote ".count($themes)." previews to {$dir}");

            return self::SUCCESS;
        }

        $event = (string) ($this->argument('event') ?: 'invoice_created');
        $event = $cards->resolveThemeKey($event);
        $this->renderOne($cards, $dir, $event);
        $this->info("Wrote {$dir}/{$event}.jpg");

        return self::SUCCESS;
    }

    protected function renderOne(WhatsAppBrandCard $cards, string $dir, string $key): void
    {
        $payload = $this->samplePayload($key);
        $path = "{$dir}/{$key}.jpg";
        $ok = $cards->renderToPath($key, $path, $payload);
        $this->line(($ok ? '✓' : '✗')." {$key}");
    }

    /**
     * @return array<string, mixed>
     */
    protected function samplePayload(string $key): array
    {
        return match (true) {
            str_starts_with($key, 'order_status_') => [
                'title' => config("whatsapp_cards.themes.{$key}.title_ar"),
                'hero' => 'ORD-1001',
                'hero_label' => 'رقم الطلب',
                'rows' => [['من', 'بانتظار'], ['إلى', 'الحالة الحالية']],
                'progress_index' => (int) config("whatsapp_cards.themes.{$key}.progress_index", 0),
            ],
            $key === 'points_awarded' => [
                'hero' => '+250',
                'hero_label' => 'نقطة',
                'rows' => [['من', 'تاجر تجريبي'], ['فاتورة', 'INV-9']],
            ],
            $key === 'invoice_created' => [
                'hero' => '1,250.00 د.ل',
                'hero_label' => 'الإجمالي',
                'rows' => [['رقم', 'INV-55'], ['من', 'جملة تجريبي'], ['إلى', 'قطاعي تجريبي']],
            ],
            $key === 'order_created' => [
                'hero' => '24',
                'hero_label' => 'نقطة',
                'rows' => [
                    ['رقم', '#36'],
                    ['حالة', 'بانتظار التأكيد'],
                    ['عميل', 'محل عماد قطاعي'],
                ],
            ],
            $key === 'return_rejected' => [
                'hero' => '✗',
                'hero_label' => 'مرفوض',
                'rows' => [['رقم', 'RET-3'], ['سبب', 'تلف ظاهر']],
            ],
            default => [
                'hero' => 'MG',
                'hero_label' => 'معاينة',
                'rows' => [['حدث', $key], ['حالة', 'تجريبي']],
                'footnote' => 'whatsapp:preview',
            ],
        };
    }
}
