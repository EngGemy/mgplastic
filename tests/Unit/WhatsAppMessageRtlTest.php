<?php

namespace Tests\Unit;

use App\Support\WhatsAppMessage;
use PHPUnit\Framework\TestCase;

class WhatsAppMessageRtlTest extends TestCase
{
    public function test_every_line_starts_with_rlm(): void
    {
        $text = WhatsAppMessage::make()
            ->brandHeader()
            ->title('فاتورة جديدة', '🧾')
            ->kv('من', 'جملة')
            ->toString();

        foreach (explode("\n", $text) as $line) {
            if ($line === '') {
                continue;
            }
            $this->assertStringStartsWith(WhatsAppMessage::RLM, $line, "Line missing RLM: {$line}");
        }

        $this->assertStringContainsString(WhatsAppMessage::COMPANY, $text);
    }

    public function test_company_name_after_rlm_keeps_mg_prefix(): void
    {
        $line = WhatsAppMessage::rtl('*'.WhatsAppMessage::COMPANY.'*');
        $this->assertSame(WhatsAppMessage::RLM.'*MG للصناعات البلاستيكية*', $line);
    }
}
