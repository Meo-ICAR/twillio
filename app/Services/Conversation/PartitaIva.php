<?php

namespace App\Services\Conversation;

/** Partita IVA italiana: 11 cifre, l'ultima è di controllo (algoritmo di Luhn adattato). */
class PartitaIva
{
    public static function isValid(string $value): bool
    {
        $digits = preg_replace('/\s+/', '', strtoupper($value));
        $digits = preg_replace('/^IT/', '', $digits);

        if (! preg_match('/^\d{11}$/', $digits) || $digits === '00000000000') {
            return false;
        }

        $sum = 0;
        foreach (str_split(substr($digits, 0, 10)) as $i => $digit) {
            $n = (int) $digit;
            if ($i % 2 === 1) {
                $n *= 2;
                $n -= $n > 9 ? 9 : 0;
            }
            $sum += $n;
        }

        return (10 - $sum % 10) % 10 === (int) $digits[10];
    }
}
