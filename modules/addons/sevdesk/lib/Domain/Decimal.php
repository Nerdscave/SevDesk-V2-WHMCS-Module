<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Domain;

/** Internal decimal helpers for validation and cent-level comparisons. */
final class Decimal
{
    private function __construct()
    {
    }

    public static function assert(string $value, string $field): string
    {
        $value = trim(str_replace(',', '.', $value));
        if (preg_match('/^-?\d+(?:\.\d{1,8})?$/', $value) !== 1) {
            throw new \InvalidArgumentException($field . ' must be a decimal number.');
        }

        if (!is_finite((float) $value)) {
            throw new \InvalidArgumentException($field . ' is outside the supported range.');
        }

        return $value;
    }

    public static function toFloat(string $value): float
    {
        return (float) self::assert($value, 'Decimal value');
    }

    /** Convert a decimal amount to cents using commercial half-up rounding. */
    public static function toMinorUnits(string $value): int
    {
        $value = self::assert($value, 'Amount');
        $negative = str_starts_with($value, '-');
        $unsigned = ltrim($value, '-');
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');

        if (strlen(ltrim($whole, '0')) > 15) {
            throw new \InvalidArgumentException('Amount is outside the supported range.');
        }

        $fraction = str_pad($fraction, 3, '0');
        $minor = ((int) $whole * 100) + (int) substr($fraction, 0, 2);
        if ((int) $fraction[2] >= 5) {
            ++$minor;
        }

        return $negative ? -$minor : $minor;
    }

    public static function grossMinorUnits(string $amount, string $taxRate, bool $net): int
    {
        if (!$net) {
            return self::toMinorUnits($amount);
        }

        $amount = self::assert($amount, 'Amount');
        $taxRate = self::assert($taxRate, 'Tax rate');
        if (str_starts_with($taxRate, '-')) {
            throw new \InvalidArgumentException('Tax rate cannot be negative.');
        }

        $negative = str_starts_with($amount, '-');
        [$amountWhole, $amountFraction] = array_pad(
            explode('.', ltrim($amount, '-'), 2),
            2,
            '',
        );
        [$rateWhole, $rateFraction] = array_pad(explode('.', $taxRate, 2), 2, '');

        $amountDigits = ltrim($amountWhole . $amountFraction, '0');
        if ($amountDigits === '') {
            return 0;
        }

        $rateScale = strlen($rateFraction);
        $rateFactor = 10 ** $rateScale;
        $rateScaled = ((int) $rateWhole * $rateFactor) + (int) ($rateFraction !== '' ? $rateFraction : '0');

        // gross cents = amount * (100 + taxRate). Work on decimal strings so
        // amounts such as 1.005 never pass through binary floating point.
        $factor = (100 * $rateFactor) + $rateScaled;
        $numerator = self::multiplyUnsignedByInt($amountDigits, $factor);
        $minor = self::roundUnsignedByPowerOfTen(
            $numerator,
            strlen($amountFraction) + $rateScale,
        );

        if (strlen($minor) > 18 || (strlen($minor) === 18 && $minor > (string) PHP_INT_MAX)) {
            throw new \InvalidArgumentException('Gross amount is outside the supported range.');
        }

        $value = (int) $minor;

        return $negative ? -$value : $value;
    }

    private static function multiplyUnsignedByInt(string $digits, int $factor): string
    {
        $carry = 0;
        $result = '';
        for ($index = strlen($digits) - 1; $index >= 0; --$index) {
            $product = ((int) $digits[$index] * $factor) + $carry;
            $result = (string) ($product % 10) . $result;
            $carry = intdiv($product, 10);
        }

        while ($carry > 0) {
            $result = (string) ($carry % 10) . $result;
            $carry = intdiv($carry, 10);
        }

        return ltrim($result, '0') ?: '0';
    }

    private static function roundUnsignedByPowerOfTen(string $digits, int $scale): string
    {
        if ($scale === 0) {
            return $digits;
        }

        $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
        $cut = strlen($digits) - $scale;
        $whole = ltrim(substr($digits, 0, $cut), '0') ?: '0';
        $remainder = substr($digits, $cut);

        if ((int) $remainder[0] < 5) {
            return $whole;
        }

        return self::incrementUnsigned($whole);
    }

    private static function incrementUnsigned(string $digits): string
    {
        $result = $digits;
        for ($index = strlen($result) - 1; $index >= 0; --$index) {
            if ($result[$index] !== '9') {
                $result[$index] = (string) ((int) $result[$index] + 1);

                return $result;
            }
            $result[$index] = '0';
        }

        return '1' . $result;
    }
}
