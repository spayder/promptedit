<?php

use App\Domain\Money;

describe('construction', function () {
    it('holds an exact number of nanodollars', function () {
        expect(Money::nanos(46_125_000)->toNanos())->toBe(46_125_000);
    });

    it('has a zero value', function () {
        expect(Money::zero()->toNanos())->toBe(0)
            ->and(Money::zero()->isZero())->toBeTrue();
    });
});

describe('decimal strings', function () {
    it('parses the 9 decimal strings Pixelforge returns', function () {
        expect(Money::fromDecimal('0.373875000')->toNanos())->toBe(373_875_000)
            ->and(Money::fromDecimal('0.000000001')->toNanos())->toBe(1);
    });

    it('parses whole numbers and short fractions', function () {
        expect(Money::fromDecimal('5')->toNanos())->toBe(5_000_000_000)
            ->and(Money::fromDecimal('5.00')->toNanos())->toBe(5_000_000_000)
            ->and(Money::fromDecimal('0.5')->toNanos())->toBe(500_000_000);
    });

    it('formats with exactly 9 decimals', function () {
        expect(Money::nanos(373_875_000)->toDecimal())->toBe('0.373875000')
            ->and(Money::nanos(1)->toDecimal())->toBe('0.000000001')
            ->and(Money::zero()->toDecimal())->toBe('0.000000000')
            ->and(Money::nanos(12_000_000_000)->toDecimal())->toBe('12.000000000');
    });

    it('round-trips without drift', function () {
        foreach (['0.169080000', '0.046125001', '123.456789012'] as $value) {
            expect(Money::fromDecimal($value)->toDecimal())->toBe($value);
        }
    });

    it('formats negative amounts', function () {
        expect(Money::nanos(-1_500_000_000)->toDecimal())->toBe('-1.500000000')
            ->and(Money::fromDecimal('-0.000000001')->toNanos())->toBe(-1);
    });

    it('rejects malformed or too precise input', function (string $input) {
        Money::fromDecimal($input);
    })->with([
        'empty' => [''],
        'text' => ['abc'],
        'comma separator' => ['1,5'],
        'trailing dot' => ['1.'],
        'leading dot' => ['.5'],
        'whitespace' => [' 1.0'],
        'exponent' => ['1e-3'],
        'more than 9 decimals' => ['0.0000000001'],
    ])->throws(InvalidArgumentException::class);
});

describe('arithmetic', function () {
    it('adds and subtracts exactly', function () {
        $a = Money::fromDecimal('0.000575000');
        $b = Money::fromDecimal('0.015300000');

        expect($a->add($b)->toDecimal())->toBe('0.015875000')
            ->and($b->subtract($a)->toDecimal())->toBe('0.014725000');
    });

    it('does not suffer from float drift', function () {
        $sum = Money::zero();
        for ($i = 0; $i < 10; $i++) {
            $sum = $sum->add(Money::fromDecimal('0.1'));
        }

        expect($sum->toDecimal())->toBe('1.000000000');
    });

    it('multiplies by an integer quantity', function () {
        expect(Money::nanos(5_000)->multiply(115)->toNanos())->toBe(575_000);
    });

    it('compares by value', function () {
        expect(Money::nanos(5)->equals(Money::nanos(5)))->toBeTrue()
            ->and(Money::nanos(5)->equals(Money::nanos(6)))->toBeFalse();
    });

    it('throws instead of silently overflowing into a float', function () {
        Money::nanos(PHP_INT_MAX)->add(Money::nanos(1));
    })->throws(OverflowException::class);
});

describe('markup', function () {
    it('applies a 25% markup exactly when it divides evenly', function () {
        // 0.373875000 * 1.25 = 0.467343750
        expect(Money::fromDecimal('0.373875000')->withMarkup(25)->toDecimal())->toBe('0.467343750');
    });

    it('rounds up to the next nanodollar when the result is fractional', function () {
        // 1 nano * 1.25 = 1.25 -> 2
        expect(Money::nanos(1)->withMarkup(25)->toNanos())->toBe(2)
            // 2 nano * 1.25 = 2.5 -> 3
            ->and(Money::nanos(2)->withMarkup(25)->toNanos())->toBe(3)
            // 3 nano * 1.25 = 3.75 -> 4
            ->and(Money::nanos(3)->withMarkup(25)->toNanos())->toBe(4)
            // 4 nano * 1.25 = 5 exactly
            ->and(Money::nanos(4)->withMarkup(25)->toNanos())->toBe(5);
    });

    it('keeps zero free', function () {
        expect(Money::zero()->withMarkup(25)->isZero())->toBeTrue();
    });

    it('never charges less than the provider cost', function () {
        foreach ([1, 7, 999, 46_125_000, 373_875_001] as $nanos) {
            expect(Money::nanos($nanos)->withMarkup(25)->toNanos())->toBeGreaterThanOrEqual($nanos);
        }
    });

    it('rejects negative amounts and negative percentages', function () {
        expect(fn () => Money::nanos(-1)->withMarkup(25))->toThrow(InvalidArgumentException::class)
            ->and(fn () => Money::nanos(1)->withMarkup(-5))->toThrow(InvalidArgumentException::class);
    });
});
