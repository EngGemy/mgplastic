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

    public const ICON_SUPPLY = '✅';

    /** @var list<string> */
    protected array $lines = [];

    protected ?string $deepLinkUrl = null;

    public static function make(): self
    {
        return new self;
    }

    public static function rtl(string $line): string
    {
        if ($line === '') {
            return '';
        }

        // Never prefix bare URLs — WhatsApp won't make them tappable if RLM comes first.
        if (self::isBareUrl($line)) {
            return ltrim($line, self::RLM." \t");
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

    /** True when the whole line is an http(s) URL (optional surrounding whitespace / RLM). */
    public static function isBareUrl(string $line): bool
    {
        $trimmed = trim(ltrim($line, self::RLM." \t"));

        return (bool) preg_match('#^https?://\S+$#iu', $trimmed);
    }

    /** Meta-like header: event emoji + bold company + separator. */
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

    /** Universal / App Link — appears as a tappable URL (WAHA has no interactive buttons). */
    public function openInApp(?string $url): self
    {
        $this->deepLinkUrl = filled($url) ? (string) $url : null;

        return $this;
    }

    public function footer(?string $deepLink = null): self
    {
        if (filled($deepLink)) {
            $this->deepLinkUrl = (string) $deepLink;
        }

        $this->lines[] = '';
        $this->lines[] = '━━━━━━━━━━━━━━━━';
        $this->lines[] = 'شكرًا لتعاملكم مع *'.self::COMPANY.'*';

        if (filled($this->deepLinkUrl)) {
            $this->lines[] = '';
            $this->lines[] = '📱 *افتح في التطبيق*';
            $this->lines[] = $this->deepLinkUrl;
        } else {
            $fallback = trim((string) (
                config('services.waha.app_url')
                ?: config('portal.plumber_app_url')
                ?: ''
            ));
            if ($fallback !== '') {
                $this->lines[] = '';
                $this->lines[] = '📱 *التطبيق*';
                $this->lines[] = $fallback;
            }
        }

        return $this;
    }

    /** Short Meta-style OTP (text only, no image, no deep-link secrets). */
    public static function otp(string $code): string
    {
        return self::make()
            ->header(self::ICON_OTP)
            ->line("رمز التحقق: *{$code}*")
            ->line('صالح *5* دقائق — لا تشاركه.')
            ->footer()
            ->toString();
    }

    /**
     * Clone body with a different greeting (same image card for all recipients).
     */
    public function withGreet(?string $name): self
    {
        $clone = clone $this;
        $clone->lines = $this->lines;
        // Rebuild: if first content line is greet, replace; else prepend after header block
        $out = [];
        $greeted = false;
        $afterHeader = false;
        foreach ($clone->lines as $i => $line) {
            if (! $afterHeader && str_contains($line, '━━')) {
                $out[] = $line;
                $afterHeader = true;
                $name = trim((string) $name);
                if ($name !== '') {
                    $out[] = "أهلاً *{$name}*،";
                }
                $greeted = true;

                continue;
            }
            if ($greeted && preg_match('/^أهلاً \*/u', $line)) {
                continue;
            }
            $out[] = $line;
        }
        $clone->lines = $out;

        return $clone;
    }

    public function toString(): string
    {
        return implode("\n", array_map(
            fn (string $line) => $line === '' ? '' : self::rtl($line),
            $this->lines
        ));
    }
}

