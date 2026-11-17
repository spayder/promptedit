<?php

use App\Domain\Money;
use App\Http\Presenters\MoneyPresenter;

function usd(string $decimal): Money
{
    return Money::fromDecimal($decimal);
}

describe('display rounding', function () {
    it('rounds to the nearest hundredth of a cent', function (string $amount, string $shown) {
        expect(MoneyPresenter::nearest(usd($amount)))->toBe($shown);
    })->with([
        'exact' => ['0.017700000', '$0.0177'],
        'rounds down' => ['0.017746875', '$0.0177'],
        'rounds up' => ['0.017750000', '$0.0178'],
        'zero' => ['0', '$0.0000'],
        'dollars' => ['12.345650000', '$12.3457'],
        'sub-unit amounts' => ['0.000000001', '$0.0000'],
    ]);

    it('rounds a minimum down and a maximum up, so a range never excludes the truth', function () {
        $amount = usd('0.017746875');

        expect(MoneyPresenter::roundedDown($amount))->toBe('$0.0177')
            ->and(MoneyPresenter::roundedUp($amount))->toBe('$0.0178');
    });

    it('does not change an amount that is already exact', function () {
        expect(MoneyPresenter::roundedDown(usd('0.014000000')))->toBe('$0.0140')
            ->and(MoneyPresenter::roundedUp(usd('0.014000000')))->toBe('$0.0140');
    });

    it('carries into the dollars', function () {
        expect(MoneyPresenter::roundedUp(usd('0.999950001')))->toBe('$1.0000')
            ->and(MoneyPresenter::nearest(usd('0.999950000')))->toBe('$1.0000');
    });

    it('never goes through floating point', function () {
        // 0.29 * 100 style traps: a huge amount stays exact.
        expect(MoneyPresenter::nearest(usd('9007199.254740993')))->toBe('$9007199.2547');
    });

    it('refuses negative amounts', function () {
        expect(fn () => MoneyPresenter::nearest(usd('-1')))->toThrow(InvalidArgumentException::class);
    });
});

describe('exact amounts', function () {
    it('drops trailing zeros but keeps at least cents', function (string $amount, string $shown) {
        expect(MoneyPresenter::exact(usd($amount)))->toBe($shown);
    })->with([
        ['0.017746875', '0.017746875'],
        ['0.014000000', '0.014'],
        ['1.500000000', '1.50'],
        ['2', '2.00'],
        ['0', '0.00'],
        ['0.000000001', '0.000000001'],
    ]);
});
