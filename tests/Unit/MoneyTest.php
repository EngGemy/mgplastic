<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_formats_money_and_points_with_latin_digits(): void
    {
        $this->assertSame('1,250.00 د.ل', Money::format(1250));
        $this->assertSame('1,250.50 د.ل', Money::fromCents(125050));
        $this->assertSame('1,250 نقطة', Money::points(1250));
    }
}
