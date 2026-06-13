<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Filament\Support\MoneyInput;
use PHPUnit\Framework\TestCase;

/**
 * Guards the Slide 4 fix: the live offer form previews must parse the money
 * mask's grouped value ("5,000") as 5000.0, not the 5.0 a bare (float) cast
 * produces — that mismatch is why the in-form total looked wrong while the
 * printed PDF was right.
 */
class MoneyInputTest extends TestCase
{
    public function test_it_strips_thousands_separators_before_casting(): void
    {
        $this->assertSame(5000.0, MoneyInput::toFloat('5,000'));
        $this->assertSame(1234567.89, MoneyInput::toFloat('1,234,567.89'));
    }

    public function test_it_handles_plain_and_empty_values(): void
    {
        $this->assertSame(5000.0, MoneyInput::toFloat('5000'));
        $this->assertSame(42.5, MoneyInput::toFloat(42.5));
        $this->assertSame(0.0, MoneyInput::toFloat(null));
        $this->assertSame(0.0, MoneyInput::toFloat(''));
    }

    public function test_a_line_total_is_computed_correctly_from_masked_inputs(): void
    {
        // 1 × "5,000" must be 5,000 — not 5 (the reported bug).
        $qty = MoneyInput::toFloat('1');
        $unitPrice = MoneyInput::toFloat('5,000');

        $this->assertSame(5000.0, $qty * $unitPrice);
    }
}
