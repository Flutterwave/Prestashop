<?php
/**
 * Flutterwave Transaction Verifier
 *
 * Checks a transaction returned by the Flutterwave verify API against the
 * cart or order it is meant to pay for.
 *
 * @author    Flutterwave Payment
 * @copyright 2024 Flutterwave Payment
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace FlutterwavePayment\classes;

if (!defined('_PS_VERSION_')) {
    exit;
}

class FlutterwaveTransactionVerifier
{
    const REFERENCE_PREFIX = 'PS_';
    const AMOUNT_TOLERANCE = 0.01;

    /**
     * Extract the cart ID from a reference (format: {PREFIX}CARTID_TIMESTAMP_RANDOM)
     *
     * @param string $reference
     * @return int|null
     */
    public static function cartIdFromReference($reference)
    {
        $pattern = '/^' . preg_quote(self::REFERENCE_PREFIX, '/') . '(\d+)_/';

        if (preg_match($pattern, (string) $reference, $matches) && (int) $matches[1] > 0) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * @param string $reference
     * @param int    $cartId
     * @return bool
     */
    public static function referenceBelongsToCart($reference, $cartId)
    {
        return (int) $cartId > 0 && self::cartIdFromReference($reference) === (int) $cartId;
    }

    public static function isSuccessful($status)
    {
        return in_array($status, ['successful', 'success', 'completed'], true);
    }

    public static function isPending($status)
    {
        return in_array($status, ['pending', 'processing'], true);
    }

    public static function isFailed($status)
    {
        return in_array($status, ['failed', 'declined'], true);
    }

    /**
     * Ensure the verified transaction is the one that was requested
     *
     * @param array  $transactionData "data" object from the verify API
     * @param string $reference       Reference that was requeried
     * @throws \Exception
     */
    public static function assertReference(array $transactionData, $reference)
    {
        if (empty($reference)
            || !isset($transactionData['tx_ref'])
            || (string) $transactionData['tx_ref'] !== (string) $reference
        ) {
            throw new \Exception('Payment reference mismatch');
        }
    }

    /**
     * Ensure a successful transaction paid the expected amount and currency
     *
     * @param array  $transactionData  "data" object from the verify API
     * @param float  $expectedAmount   Cart or order total
     * @param string $expectedCurrency ISO currency code of the cart or order
     * @return string Flutterwave transaction ID
     * @throws \Exception
     */
    public static function assertPaymentMatches(array $transactionData, $expectedAmount, $expectedCurrency)
    {
        if (empty($transactionData['id'])) {
            throw new \Exception('Transaction ID missing from verification response');
        }

        if (!isset($transactionData['amount'])
            || !is_numeric($transactionData['amount'])
            || abs((float) $transactionData['amount'] - (float) $expectedAmount) > self::AMOUNT_TOLERANCE
        ) {
            throw new \Exception('Payment amount mismatch');
        }

        if (!isset($transactionData['currency']) || (string) $transactionData['currency'] !== (string) $expectedCurrency) {
            throw new \Exception('Payment currency mismatch');
        }

        return (string) $transactionData['id'];
    }
}
