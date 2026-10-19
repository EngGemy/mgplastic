<?php

namespace App\Support;

/**
 * Meta-style WhatsApp templates (header / body / footer).
 * Every non-empty line is prefixed with RLM (U+200F) for stable RTL.
 */
class WhatsAppMessage
{
    public const RLM = "\u{200F}";

    public const COMPANY = 'MG للصناعات البلاستيكية';

    public const ICON_INVOICE = '🧾';

    public const ICON_ORDER = '📦';

    public const ICON_RETURN = '↩️';

    public const ICON_OTP = '🔐';

    public const ICON_POINTS = '⭐';

    public const ICON_WELCOME = '🎉';

    public const ICON_STATUS = '🔔';

    public const ICON_WITHDRAWAL = '💰';

    /** @var list<string> */
    protected array $lines = [];

    public static function make(): self
    {
        return new self;
    }

    public static function rtl(string $line): string
    {
        if ($line === '') {
            return '';
        }

        return str_starts_with($line, self::RLM) ? $line : self::RLM.$line;
    }

    public static function rtlBlock(string $text): string
    {
        $parts = preg_split("/\r\n|\n|\r/", $text) ?: [];

        return implode("\n", array_map(
            fn (string $line) => $line === '' ? '' : self::rtl($line),
            $parts
        ));
    }

    /** Meta-like header: bold company + separator. */
    public function header(string $eventIcon = ''): self
    {
        $prefix = $eventIcon !== '' ? "{$eventIcon} " : '';
        $this->lines[] = $prefix.'*'.self::COMPANY.'*';
        $this->lines[] = '━━━━━━━━━━━━━━━━';

        return $this;
    }

    public function greet(?string $name = null): self
    {
        $name = trim((string) $name);
        if ($name !== '') {
            $this->lines[] = "أهلاً *{$name}*،";
        }

        return $this;
    }

    public function event(string $icon, string $title): self
    {
        $this->lines[] = '';
        $this->lines[] = "{$icon} *{$title}*";
        $this->lines[] = '';

        return $this;
    }

    public function line(string $text = ''): self
    {
        $this->lines[] = $text;

        return $this;
    }

    /** Body row — label + bold value (Meta template body params). */
    public function param(string $label, string|int|float|null $value): self
    {
        $value = $value === null || $value === '' ? '—' : (string) $value;
        $this->lines[] = "{$label}: *{$value}*";

        return $this;
    }

    /**
     * @param  list<string>  $items
     */
    public function items(string $heading, array $items, int $limit = 8): self
    {
        if ($items === []) {
            return $this;
        }

        $this->lines[] = '';
        $this->lines[] = "*{$heading}*";
        foreach (array_slice($items, 0, $limit) as $i => $item) {
            $this->lines[] = ($i + 1).'. '.$item;
        }
        $extra = count($items) - min(count($items), $limit);
        if ($extra > 0) {
            $this->lines[] = "… و*{$extra}* أخرى";
        }

        return $this;
    }

    public function link(string $label, ?string $url): self
    {
        if (! filled($url)) {
            return $this;
        }

        $this->lines[] = '';
        $this->lines[] = "*{$label}*";
        $this->lines[] = $url;

        return $this;
    }

    public function appLink(): self
    {
        $app = trim((string) (
            config('services.waha.app_url')
            ?: config('portal.plumber_app_url')
            ?: ''
        ));

        if ($app === '') {
            return $this;
        }

        $this->lines[] = '';
        $this->lines[] = '📱 *التطبيق*';
        $this->lines[] = $app;

        return $this;
    }

    public function footer(): self
    {
        $this->lines[] = '';
        $this->lines[] = '━━━━━━━━━━━━━━━━';
        $this->lines[] = 'شكرًا لتعاملكم مع *'.self::COMPANY.'*';
        $this->appLink();

        return $this;
    }

    /** Short Meta-style OTP (text only, no image). */
    public static function otp(string $code): string
    {
        return self::make()
            ->header(self::ICON_OTP)
            ->line("رمز التحقق: *{$code}*")
            ->line('صالح *5* دقائق — لا تشاركه.')
            ->footer()
            ->toString();
    }

    public function toString(): string
    {
        return implode("\n", array_map(
            fn (string $line) => $line === '' ? '' : self::rtl($line),
            $this->lines
        ));
    }
}
