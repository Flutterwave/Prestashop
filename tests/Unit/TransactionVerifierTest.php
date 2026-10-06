<?php

use FlutterwavePayment\classes\FlutterwaveTransactionVerifier as Verifier;

function verifiedTransaction(array $overrides = []): array
{
    return array_replace([
        'id' => 4975363,
        'tx_ref' => 'PS_42_1700000000_abcd1234',
        'status' => 'successful',
        'amount' => 50.00,
        'currency' => 'NGN',
    ], $overrides);
}

describe('references', function () {
    it('extracts the cart id', function () {
        expect(Verifier::cartIdFromReference('PS_42_1700000000_abcd1234'))->toBe(42);
    });

    it('returns null for references not minted by the module', function (?string $reference) {
        expect(Verifier::cartIdFromReference($reference))->toBeNull();
    })->with([
        'empty' => '',
        'null' => null,
        'other prefix' => 'WC_42_1700000000',
        'no separator' => 'PS_42',
        'cart zero' => 'PS_0_1700000000',
        'non numeric cart' => 'PS_abc_1700000000',
        'prefix not at start' => 'x_PS_42_1700000000',
    ]);

    it('binds a reference to its own cart only', function () {
        expect(Verifier::referenceBelongsToCart('PS_42_1700000000_abcd1234', 42))->toBeTrue()
            ->and(Verifier::referenceBelongsToCart('PS_42_1700000000_abcd1234', 43))->toBeFalse()
            ->and(Verifier::referenceBelongsToCart('PS_421_1700000000_abcd1234', 42))->toBeFalse()
            ->and(Verifier::referenceBelongsToCart('PS_4_1700000000_abcd1234', 42))->toBeFalse()
            ->and(Verifier::referenceBelongsToCart('', 0))->toBeFalse();
    });

    it('accepts the transaction that was requeried', function () {
        Verifier::assertReference(verifiedTransaction(), 'PS_42_1700000000_abcd1234');
    })->throwsNoExceptions();

    it('rejects a transaction for a different reference', function (array $transaction, string $reference) {
        Verifier::assertReference($transaction, $reference);
    })->with([
        'other cart' => [verifiedTransaction(), 'PS_43_1700000000_abcd1234'],
        'missing tx_ref' => [verifiedTransaction(['tx_ref' => null]), 'PS_42_1700000000_abcd1234'],
        'empty reference' => [verifiedTransaction(['tx_ref' => '']), ''],
    ])->throws(Exception::class, 'Payment reference mismatch');
});

describe('statuses', function () {
    it('groups Flutterwave statuses', function (string $status, bool $successful, bool $pending, bool $failed) {
        expect(Verifier::isSuccessful($status))->toBe($successful)
            ->and(Verifier::isPending($status))->toBe($pending)
            ->and(Verifier::isFailed($status))->toBe($failed);
    })->with([
        ['successful', true, false, false],
        ['success', true, false, false],
        ['completed', true, false, false],
        ['pending', false, true, false],
        ['processing', false, true, false],
        ['failed', false, false, true],
        ['declined', false, false, true],
        ['cancelled', false, false, false],
        ['Successful', false, false, false],
    ]);

    it('does not treat a missing status as successful', function () {
        expect(Verifier::isSuccessful(null))->toBeFalse();
    });
});

describe('payment amount and currency', function () {
    it('returns the transaction id when the payment matches', function () {
        expect(Verifier::assertPaymentMatches(verifiedTransaction(), 50.00, 'NGN'))->toBe('4975363');
    });

    it('allows rounding within one cent', function () {
        expect(Verifier::assertPaymentMatches(verifiedTransaction(['amount' => '50.005']), 50.00, 'NGN'))->toBe('4975363');
    });

    it('rejects an amount that does not match', function (mixed $amount) {
        Verifier::assertPaymentMatches(verifiedTransaction(['amount' => $amount]), 50.00, 'NGN');
    })->with([
        'underpaid' => 49.00,
        'overpaid' => 50.02,
        'missing' => null,
        'not numeric' => 'fifty',
    ])->throws(Exception::class, 'Payment amount mismatch');

    it('rejects a currency that does not match', function (?string $currency) {
        Verifier::assertPaymentMatches(verifiedTransaction(['currency' => $currency]), 50.00, 'NGN');
    })->with([
        'other currency' => 'USD',
        'missing' => null,
        'lowercase' => 'ngn',
    ])->throws(Exception::class, 'Payment currency mismatch');

    it('rejects a transaction without an id', function () {
        Verifier::assertPaymentMatches(verifiedTransaction(['id' => null]), 50.00, 'NGN');
    })->throws(Exception::class, 'Transaction ID missing');
});
