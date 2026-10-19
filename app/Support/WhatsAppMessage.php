<?php

namespace App\Support;

/**
 * Premium Arabic WhatsApp captions.
 * Every non-empty line is prefixed with RLM (U+200F) so mixed Latin/Arabic
 * (e.g. "MG للصناعات البلاستيكية") keeps correct RTL order.
 */
class WhatsAppMessage
{
    public const RLM = "\u{200F}";

    public const COMPANY = 'MG للصناعات البلاستيكية';

    /** @var list<string> */
    protected array $lines = [];

    public static function make(): self
    {
        return new self;
    }

    /** Prefix a single line with RLM (idempotent). */
    public static function rtl(string $line): string
    {
        if ($line === '') {
            return '';
        }

        if (str_starts_with($line, self::RLM)) {
            return $line;
        }

        return self::RLM.$line;
    }

    /** Apply RLM to every non-empty line of a multi-line string. */
    public static function rtlBlock(string $text): string
    {
        $parts = preg_split("/\r\n|\n|\r/", $text) ?: [];

        return implode("\n", array_map(
            fn (string $line) => $line === '' ? '' : self::rtl($line),
            $parts
        ));
    }

    public function brandHeader(string $emoji = '🏭'): self
    {
        $this->lines[] = '╔══════════════════════╗';
        $this->lines[] = "║  {$emoji} *".self::COMPANY.'*';
        $this->lines[] = '║  _الجودة الليبية… ثقة السباكين_';
        $this->lines[] = '╚══════════════════════╝';

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
        // URLs stay LTR — still prefix RLM so the label line stays RTL in context
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
        $settings = \App\Models\WebsiteSetting::instance();
        $support = trim((string) ($settings->contact_whatsapp ?: $settings->contact_phone));

        $this->lines[] = '';
        $this->lines[] = '════════════════════';
        $this->lines[] = '💙 *شكراً لثقتك في '.self::COMPANY.'*';
        if ($support !== '') {
            $this->lines[] = "💬 خدمة العملاء: {$support}";
        }
        $this->lines[] = '_نحن معك في كل خطوة_';

        return $this;
    }

    public function toString(): string
    {
        return implode("\n", array_map(
            fn (string $line) => $line === '' ? '' : self::rtl($line),
            $this->lines
        ));
    }
}
