<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact decimal arithmetic for US dollar amounts, backed by BCMath.
 *
 * Amounts are plain decimal strings ("0.046125000"). Floats are rejected on
 * purpose: one stray float is all it takes for costs to drift.
 *
 * Stored amounts use STORAGE_SCALE (12) decimal places, which holds every
 * Pixelforge cost (≤ 9 places) and every 25% markup of one (≤ 11 places)
 * exactly. Intermediate maths runs at WORK_SCALE so nothing is lost before
 * the final, verified normalisation.
 */
final class Money
{
    public const STORAGE_SCALE = 12;

    private const WORK_SCALE = 24;

    private const PATTERN = '/^-?\d+(\.\d+)?$/';

    public static function add(string ...$amounts): string
    {
        $total = '0';
        foreach ($amounts as $amount) {
            $total = bcadd($total, self::assert($amount), self::WORK_SCALE);
        }

        return self::trim($total);
    }

    public static function sub(string $a, string $b): string
    {
        return self::trim(bcsub(self::assert($a), self::assert($b), self::WORK_SCALE));
    }

    public static function mul(string $a, string $b): string
    {
        return self::trim(bcmul(self::assert($a), self::assert($b), self::WORK_SCALE));
    }

    public static function div(string $a, string $b): string
    {
        if (bccomp(self::assert($b), '0', self::WORK_SCALE) === 0) {
            throw new InvalidArgumentException('Division by zero.');
        }

        return self::trim(bcdiv(self::assert($a), $b, self::WORK_SCALE));
    }

    public static function compare(string $a, string $b): int
    {
        return bccomp(self::assert($a), self::assert($b), self::WORK_SCALE);
    }

    public static function isZero(string $amount): bool
    {
        return self::compare($amount, '0') === 0;
    }

    public static function isNegative(string $amount): bool
    {
        return self::compare($amount, '0') < 0;
    }

    public static function max(string $a, string $b): string
    {
        return self::compare($a, $b) >= 0 ? self::trim($a) : self::trim($b);
    }

    public static function min(string $a, string $b): string
    {
        return self::compare($a, $b) <= 0 ? self::trim($a) : self::trim($b);
    }

    /**
     * Format for storage/transport with exactly STORAGE_SCALE places.
     * Refuses to silently drop precision: if the value has more significant
     * decimals than we store, that's a bug worth failing loudly on.
     */
    public static function normalize(string $amount): string
    {
        $amount = self::assert($amount);
        $scaled = bcadd($amount, '0', self::STORAGE_SCALE);

        if (bccomp($scaled, $amount, self::WORK_SCALE) !== 0) {
            throw new InvalidArgumentException("Amount {$amount} needs more than ".self::STORAGE_SCALE.' decimal places.');
        }

        return $scaled === '-0.'.str_repeat('0', self::STORAGE_SCALE) ? substr($scaled, 1) : $scaled;
    }

    /**
     * Round half-up to a number of places. Only used for presentation
     * (e.g. showing cents); never for stored or billed amounts.
     */
    public static function roundForDisplay(string $amount, int $places = 2): string
    {
        $amount = self::assert($amount);
        $offset = '0.'.str_repeat('0', $places).'5';
        $rounded = self::isNegative($amount)
            ? bcsub($amount, $offset, $places)
            : bcadd($amount, $offset, $places);

        return $rounded;
    }

    private static function assert(string $amount): string
    {
        if (! preg_match(self::PATTERN, $amount)) {
            throw new InvalidArgumentException("Invalid decimal amount: [{$amount}].");
        }

        return $amount;
    }

    private static function trim(string $amount): string
    {
        if (! str_contains($amount, '.')) {
            return $amount;
        }

        $amount = rtrim(rtrim($amount, '0'), '.');

        return $amount === '-0' ? '0' : $amount;
    }
}
