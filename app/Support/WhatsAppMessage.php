<?php

namespace App\Support;

use App\Models\WebsiteSetting;

/**
 * Premium Arabic WhatsApp captions (paired with MG brand card image).
 */
class WhatsAppMessage
{
    /** @var list<string> */
    protected array $lines = [];

    public static function make(): self
    {
        return new self;
    }

    public function brandHeader(string $emoji = '🏭'): self
    {
        $site = WebsiteSetting::instance()->site_name ?: 'MG Plastic';

        $this->lines[] = "╔══════════════════════╗";
        $this->lines[] = "║  {$emoji} *{$site}*";
        $this->lines[] = "║  _الجودة الليبية… ثقة السباكين_";
        $this->lines[] = "╚══════════════════════╝";

        return $this;
    }

    public function greet(?string $name = null): self
    {
        $name = trim((string) $name);
        $this->lines[] = '';
        $this->lines[] = $name !== ''
            ? "✨ أهلاً *{$name}*"
            : '✨ أهلاً بك في عائلة MG';

        return $this;
    }

    public function title(string $title, string $emoji = '✨'): self
    {
        $this->lines[] = '';
        $this->lines[] = "{$emoji} *{$title}*";
        $this->lines[] = '────────────────────';

        return $this;
    }

    public function line(string $text = ''): self
    {
        $this->lines[] = $text;

        return $this;
    }

    public function kv(string $label, string|int|float|null $value): self
    {
        $value = $value === null || $value === '' ? '—' : (string) $value;
        $this->lines[] = "🔹 *{$label}:* {$value}";

        return $this;
    }

    /**
     * @param  list<string>  $items
     */
    public function bulletList(string $heading, array $items, int $limit = 8): self
    {
        if ($items === []) {
            return $this;
        }

        $this->lines[] = '';
        $this->lines[] = "📦 *{$heading}*";
        $shown = array_slice($items, 0, $limit);
        foreach ($shown as $i => $item) {
            $n = $i + 1;
            $this->lines[] = "   {$n}. {$item}";
        }
        $extra = count($items) - count($shown);
        if ($extra > 0) {
            $this->lines[] = "   … و*{$extra}* أصناف أخرى";
        }

        return $this;
    }

    public function link(string $label, ?string $url): self
    {
        if (! filled($url)) {
            return $this;
        }

        $this->lines[] = '';
        $this->lines[] = "🔗 *{$label}*";
        $this->lines[] = $url;

        return $this;
    }

    public function appLinks(): self
    {
        $app = trim((string) (
            config('services.waha.app_url')
            ?: config('portal.plumber_app_url')
            ?: config('app.url')
        ));

        if ($app !== '') {
            $this->lines[] = '';
            $this->lines[] = '📱 *حمّل / افتح تطبيق MG*';
            $this->lines[] = $app;
        }

        return $this;
    }

    public function footer(): self
    {
        $settings = WebsiteSetting::instance();
        $support = trim((string) ($settings->contact_whatsapp ?: $settings->contact_phone));

        $this->lines[] = '';
        $this->lines[] = '════════════════════';
        $this->lines[] = '💙 *شكراً لثقتك في MG*';
        if ($support !== '') {
            $this->lines[] = "💬 خدمة العملاء: {$support}";
        }
        $this->lines[] = '_نحن معك في كل خطوة_';

        return $this;
    }

    public function toString(): string
    {
        return implode("\n", $this->lines);
    }
}
