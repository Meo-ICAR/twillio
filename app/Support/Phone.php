<?php

namespace App\Support;

/** Numeri di telefono scritti in modi diversi (+39, 0039, spazi, trattini) ricondotti alla stessa forma. */
class Phone
{
    /** Solo cifre, senza prefisso internazionale italiano (+39 / 0039). */
    public static function national(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        $digits = preg_replace('/^00/', '', $digits);

        return strlen($digits) >= 11 && str_starts_with($digits, '39') ? substr($digits, 2) : $digits;
    }

    public static function same(string $a, string $b): bool
    {
        $a = self::national($a);

        return $a !== '' && $a === self::national($b);
    }
}
