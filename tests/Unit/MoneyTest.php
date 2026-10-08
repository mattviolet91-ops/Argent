<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_format_uses_french_conventions(): void
    {
        $this->assertSame("1\u{202F}234,56\u{00A0}€", Money::format(123456));
        $this->assertSame("0,05\u{00A0}€", Money::format(5));
        $this->assertSame("−12,00\u{00A0}€", Money::format(-1200));
        $this->assertSame('12,00', Money::format(1200, false));
    }

    public function test_parse_accepts_common_inputs(): void
    {
        $this->assertSame(123456, Money::parse('1 234,56'));
        $this->assertSame(123456, Money::parse('1234.56 €'));
        $this->assertSame(150, Money::parse('1,5'));
        $this->assertSame(1000, Money::parse('10'));
        $this->assertSame(-250, Money::parse('-2,50'));
        $this->assertSame(1999, Money::parse('19.99'), 'Aucune erreur d\'arrondi flottant.');
    }

    public function test_parse_rejects_invalid_inputs(): void
    {
        $this->assertNull(Money::parse(''));
        $this->assertNull(Money::parse('abc'));
        $this->assertNull(Money::parse('1,234'));
        $this->assertNull(Money::parse('1.2.3'));
    }
}
