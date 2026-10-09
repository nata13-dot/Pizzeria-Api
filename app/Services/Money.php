<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

/** Decimal strings, including on 32-bit PHP. Monetary boundaries use HALF_UP. */
final class Money
{
    public static function decimal(string|int|float $value): BigDecimal
    {
        try {
            return BigDecimal::of((string) $value);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['amount' => 'El importe no es válido.']);
        }
    }

    public static function normalize(string|int|float $value): string
    {
        $decimal = self::decimal($value)->toScale(2, RoundingMode::HALF_UP);
        if ($decimal->isNegative() || $decimal->isGreaterThan('9999999999.99')) {
            throw ValidationException::withMessages(['amount' => 'El importe supera el rango permitido.']);
        }

        return (string) $decimal;
    }

    public static function sum(iterable $values): string
    {
        $sum = BigDecimal::zero();
        foreach ($values as $value) {
            $sum = $sum->plus(self::normalize($value));
        }

        return self::normalize((string) $sum);
    }

    public static function multiply(string|int|float $unit, string|int|float $quantity): string
    {
        return self::normalize((string) self::decimal(self::normalize($unit))->multipliedBy(self::decimal($quantity)));
    }

    public static function total(string $subtotal, string|int|float $discount, string $delivery): string
    {
        $total = self::decimal($subtotal)->minus(self::normalize($discount))->plus($delivery);

        return $total->isNegative() ? '0.00' : self::normalize((string) $total);
    }

    public static function compare(string|int|float $left, string|int|float $right): int
    {
        return self::decimal(self::normalize($left))->compareTo(self::normalize($right));
    }
}
