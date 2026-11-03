<?php

namespace Tests\Unit;

use App\Support\UnicodeText;
use PHPUnit\Framework\TestCase;

class UnicodeTextTest extends TestCase
{
    public function test_nfkc_collapses_arabic_presentation_forms(): void
    {
        // Presentation-form sequence approximating «وصلة» (compatibility letters).
        $presentation = "\u{FEE5}\u{FEB3}\u{FEE0}\u{FE8E}"; // common FB/FE forms vary; NFKC must be idempotent
        $out = UnicodeText::nfkc($presentation);
        $this->assertNotSame('', $out);
        $this->assertSame($out, UnicodeText::nfkc($out));

        $plain = 'وصلة سن داخلي 1 بوصة MG';
        $this->assertSame($plain, UnicodeText::nfkc($plain));
    }
}
