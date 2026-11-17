<?php

use App\Infrastructure\Pixelforge\InvalidWebhookSignature;
use App\Infrastructure\Pixelforge\WebhookSignatureVerifier;
use Tests\Fixtures\PixelforgeWebhook;

const NOW = 1_762_265_009;
const BODY = '{"id":"evt_1","type":"generation.succeeded"}';

function verifier(int $tolerance = 300): WebhookSignatureVerifier
{
    return new WebhookSignatureVerifier(PixelforgeWebhook::SECRET, $tolerance);
}

describe('accepting', function () {
    it('accepts a correctly signed body', function () {
        verifier()->verify(BODY, PixelforgeWebhook::signature(BODY, NOW), NOW);

        expect(true)->toBeTrue(); // no exception
    });

    it('accepts the edge of the tolerance window, in the past and the future', function (int $offset) {
        verifier(300)->verify(BODY, PixelforgeWebhook::signature(BODY, NOW + $offset), NOW);

        expect(true)->toBeTrue();
    })->with([-300, 300, 0]);

    it('accepts when one of several v1 signatures is valid (secret rotation)', function () {
        $valid = PixelforgeWebhook::signature(BODY, NOW);
        $header = str_replace(',v1=', ',v1=deadbeef,v1=', $valid);

        verifier()->verify(BODY, $header.',v1=00', NOW);

        expect(true)->toBeTrue();
    });

    it('tolerates spaces around the header parts', function () {
        [$t, $v1] = explode(',', PixelforgeWebhook::signature(BODY, NOW));

        verifier()->verify(BODY, "$t, $v1", NOW);

        expect(true)->toBeTrue();
    });
});

describe('rejecting', function () {
    it('rejects a body that was changed after signing', function () {
        $header = PixelforgeWebhook::signature(BODY, NOW);

        expect(fn () => verifier()->verify(BODY.' ', $header, NOW))->toThrow(InvalidWebhookSignature::class, 'does not match');
    });

    it('rejects JSON that means the same but is not the same bytes', function () {
        $header = PixelforgeWebhook::signature(BODY, NOW);
        $reencoded = json_encode(json_decode(BODY, true), JSON_PRETTY_PRINT);

        expect(fn () => verifier()->verify($reencoded, $header, NOW))->toThrow(InvalidWebhookSignature::class);
    });

    it('rejects a signature made with another secret', function () {
        $header = PixelforgeWebhook::signature(BODY, NOW, secret: 'whsec_someone_else');

        expect(fn () => verifier()->verify(BODY, $header, NOW))->toThrow(InvalidWebhookSignature::class, 'does not match');
    });

    it('rejects a timestamp swapped after signing', function () {
        [, $v1] = explode(',', PixelforgeWebhook::signature(BODY, NOW));

        expect(fn () => verifier()->verify(BODY, 't='.(NOW + 1).','.$v1, NOW))->toThrow(InvalidWebhookSignature::class);
    });

    it('rejects an old but correctly signed request (replay)', function () {
        $header = PixelforgeWebhook::signature(BODY, NOW - 301);

        expect(fn () => verifier()->verify(BODY, $header, NOW))->toThrow(InvalidWebhookSignature::class, 'window');
    });

    it('rejects a timestamp from the future', function () {
        $header = PixelforgeWebhook::signature(BODY, NOW + 301);

        expect(fn () => verifier()->verify(BODY, $header, NOW))->toThrow(InvalidWebhookSignature::class, 'window');
    });

    it('rejects a missing or unreadable header', function (?string $header) {
        expect(fn () => verifier()->verify(BODY, $header, NOW))->toThrow(InvalidWebhookSignature::class);
    })->with([
        'missing' => [null],
        'empty' => [''],
        'garbage' => ['hello'],
        'no timestamp' => ['v1=abc'],
        'no signature' => ['t='.NOW],
        'timestamp not a number' => ['t=abc,v1=abc'],
        'negative timestamp' => ['t=-5,v1=abc'],
        'empty signature' => ['t='.NOW.',v1='],
    ]);

    it('refuses to be built without a secret', function () {
        expect(fn () => new WebhookSignatureVerifier('  ', 300))->toThrow(InvalidArgumentException::class);
    });
});
