<?php

namespace Tests\Unit;

use App\Support\WhatsAppMessage;
use Tests\TestCase;

class WhatsAppMessageRtlTest extends TestCase
{
    public function test_every_line_starts_with_rlm(): void
    {
        $text = WhatsAppMessage::make()
            ->header(WhatsAppMessage::ICON_INVOICE)
            ->event(WhatsAppMessage::ICON_INVOICE, 'فاتورة جديدة')
            ->param('من', 'جملة')
            ->param('الإجمالي', '100')
            ->footer()
            ->toString();

        foreach (explode("\n", $text) as $line) {
            if ($line === '') {
                continue;
            }
            $this->assertStringStartsWith(WhatsAppMessage::RLM, $line, "Line missing RLM: {$line}");
        }

        $this->assertStringContainsString('*'.WhatsAppMessage::COMPANY.'*', $text);
        $this->assertStringContainsString('━━━━━━━━', $text);
        $this->assertStringContainsString('شكرًا لتعاملكم مع', $text);
        $this->assertStringContainsString('*جملة*', $text);
        $this->assertStringContainsString('*100*', $text);
    }

    public function test_company_name_after_rlm_keeps_mg_prefix(): void
    {
        $line = WhatsAppMessage::rtl('*'.WhatsAppMessage::COMPANY.'*');
        $this->assertSame(WhatsAppMessage::RLM.'*MG للصناعات البلاستيكية*', $line);
    }

    public function test_otp_is_short_meta_style_with_bold_code(): void
    {
        $text = WhatsAppMessage::otp('123456');

        $this->assertStringContainsString(WhatsAppMessage::ICON_OTP, $text);
        $this->assertStringContainsString('*123456*', $text);
        $this->assertStringContainsString('*'.WhatsAppMessage::COMPANY.'*', $text);
        $this->assertStringContainsString('شكرًا لتعاملكم مع', $text);
        $this->assertStringNotContainsString('/open/', $text);

        foreach (explode("\n", $text) as $line) {
            if ($line === '') {
                continue;
            }
            $this->assertStringStartsWith(WhatsAppMessage::RLM, $line);
        }
    }

    public function test_open_in_app_deep_link_line(): void
    {
        $text = WhatsAppMessage::make()
            ->header(WhatsAppMessage::ICON_ORDER)
            ->param('رقم الطلب', '1')
            ->openInApp('https://mg-plastic.com/open/order/1')
            ->footer()
            ->toString();

        $this->assertStringContainsString('افتح في التطبيق', $text);
        $this->assertStringContainsString("https://mg-plastic.com/open/order/1", $text);

        // Bare URL must NOT start with RLM or WhatsApp won't auto-link it
        $urlLine = null;
        foreach (explode("\n", $text) as $line) {
            if (str_starts_with(ltrim($line, WhatsAppMessage::RLM), 'https://')) {
                $urlLine = $line;
                break;
            }
        }
        $this->assertNotNull($urlLine);
        $this->assertSame('https://mg-plastic.com/open/order/1', $urlLine);
        $this->assertFalse(str_starts_with($urlLine, WhatsAppMessage::RLM));
    }

    public function test_rtl_block_preserves_bare_urls(): void
    {
        $block = WhatsAppMessage::rtlBlock("أهلاً\nhttps://mg-plastic.com/open/invoice/9\nشكرًا");
        $lines = explode("\n", $block);

        $this->assertStringStartsWith(WhatsAppMessage::RLM, $lines[0]);
        $this->assertSame('https://mg-plastic.com/open/invoice/9', $lines[1]);
        $this->assertStringStartsWith(WhatsAppMessage::RLM, $lines[2]);
    }
}
