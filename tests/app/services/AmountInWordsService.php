<?php
/**
 * AmountInWordsService — converts a numeric amount to English words for
 * the printable Internal Voucher's "Amount in Words" line. Small,
 * self-contained, no external dependency. Handles up to billions with
 * two-decimal cents.
 */
class AmountInWordsService
{
    private const ONES = [
        '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
        'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
        'Seventeen', 'Eighteen', 'Nineteen',
    ];
    private const TENS = [
        '', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety',
    ];

    public static function convert(float $amount, string $currency = 'Uganda Shillings'): string
    {
        $amount = round($amount, 2);
        $whole = (int)floor($amount);
        $cents = (int)round(($amount - $whole) * 100);

        $wholeWords = $whole === 0 ? 'Zero' : self::convertInteger($whole);
        $result = trim($wholeWords) . ' ' . $currency;

        if ($cents > 0) {
            $result .= ' and ' . trim(self::convertInteger($cents)) . ' Cents';
        }

        return $result . ' Only';
    }

    private static function convertInteger(int $n): string
    {
        if ($n === 0) {
            return '';
        }
        if ($n < 0) {
            return 'Negative ' . self::convertInteger(-$n);
        }

        $parts = [];
        $billions = intdiv($n, 1000000000);
        $n %= 1000000000;
        $millions = intdiv($n, 1000000);
        $n %= 1000000;
        $thousands = intdiv($n, 1000);
        $n %= 1000;
        $hundreds = $n;

        if ($billions > 0) {
            $parts[] = self::convertUnder1000($billions) . ' Billion';
        }
        if ($millions > 0) {
            $parts[] = self::convertUnder1000($millions) . ' Million';
        }
        if ($thousands > 0) {
            $parts[] = self::convertUnder1000($thousands) . ' Thousand';
        }
        if ($hundreds > 0) {
            $parts[] = self::convertUnder1000($hundreds);
        }

        return implode(' ', $parts);
    }

    private static function convertUnder1000(int $n): string
    {
        $parts = [];
        if ($n >= 100) {
            $parts[] = self::ONES[intdiv($n, 100)] . ' Hundred';
            $n %= 100;
        }
        if ($n >= 20) {
            $tens = self::TENS[intdiv($n, 10)];
            $ones = $n % 10;
            $parts[] = $ones > 0 ? $tens . '-' . self::ONES[$ones] : $tens;
        } elseif ($n > 0) {
            $parts[] = self::ONES[$n];
        }
        return implode(' ', $parts);
    }
}
