<?php

namespace App\Services\Conversation;

use Illuminate\Support\Str;

/** Interpreta il codice fiscale: data, sesso e luogo di nascita, e coerenza con cognome e nome. */
final class CodiceFiscale
{
    private const PATTERN = '/^[A-Z]{6}[0-9LMNPQRSTUV]{2}[ABCDEHLMPRST][0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{3}[A-Z]$/';

    private const MONTHS = 'ABCDEHLMPRST';

    private const OMOCODIA = ['L' => 0, 'M' => 1, 'N' => 2, 'P' => 3, 'Q' => 4, 'R' => 5, 'S' => 6, 'T' => 7, 'U' => 8, 'V' => 9];

    /** @var array<string,string>|null codice catastale => comune (provincia), fonte ISTAT */
    private static ?array $places = null;

    public static function normalize(string $cf): string
    {
        return strtoupper(preg_replace('/\s+/', '', $cf));
    }

    /**
     * @return array{birth_date: string, sex: string, belfiore: string, place: ?string}|null
     */
    public static function parse(string $cf, ?int $referenceYear = null): ?array
    {
        $cf = self::normalize($cf);
        if (! preg_match(self::PATTERN, $cf)) {
            return null;
        }

        $chars = str_split($cf);
        foreach ([6, 7, 9, 10, 12, 13, 14] as $i) {
            if (isset(self::OMOCODIA[$chars[$i]])) {
                $chars[$i] = (string) self::OMOCODIA[$chars[$i]];
            }
        }

        $year2 = (int) ($chars[6].$chars[7]);
        $month = strpos(self::MONTHS, $chars[8]) + 1;
        $day = (int) ($chars[9].$chars[10]);
        $sex = $day > 40 ? 'F' : 'M';
        $day = $day > 40 ? $day - 40 : $day;

        $reference = $referenceYear ?? (int) date('Y');
        $century = intdiv($reference, 100) * 100;
        $year = $year2 <= $reference % 100 ? $century + $year2 : $century - 100 + $year2;

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        $belfiore = $chars[11].$chars[12].$chars[13].$chars[14];

        return [
            'birth_date' => sprintf('%02d/%02d/%04d', $day, $month, $year),
            'sex' => $sex,
            'belfiore' => $belfiore,
            'place' => self::place($belfiore),
        ];
    }

    public static function surnameCode(string $surname): string
    {
        [$consonants, $vowels] = self::letters($surname);

        return self::fill(array_slice($consonants, 0, 3), $vowels);
    }

    public static function nameCode(string $name): string
    {
        [$consonants, $vowels] = self::letters($name);

        if (count($consonants) >= 4) {
            return $consonants[0].$consonants[2].$consonants[3];
        }

        return self::fill($consonants, $vowels);
    }

    /** @return list<array{field: string, expected: string, found: string}> */
    public static function mismatches(string $cf, string $surname, string $name): array
    {
        $cf = self::normalize($cf);
        $result = [];

        foreach ([['cognome', self::surnameCode($surname), substr($cf, 0, 3)], ['nome', self::nameCode($name), substr($cf, 3, 3)]] as [$field, $expected, $found]) {
            if ($expected !== $found) {
                $result[] = ['field' => $field, 'expected' => $expected, 'found' => $found];
            }
        }

        return $result;
    }

    /** @return array{0: string[], 1: string[]} consonanti e vocali, nell'ordine in cui compaiono */
    private static function letters(string $text): array
    {
        $letters = str_split(preg_replace('/[^A-Z]/', '', strtoupper(Str::ascii($text))));

        $vowels = array_values(array_filter($letters, fn ($l) => str_contains('AEIOU', $l)));
        $consonants = array_values(array_filter($letters, fn ($l) => ! str_contains('AEIOU', $l)));

        return [$consonants, $vowels];
    }

    private static function fill(array $consonants, array $vowels): string
    {
        $code = array_slice(array_merge($consonants, $vowels), 0, 3);

        return str_pad(implode('', $code), 3, 'X');
    }

    private static function place(string $belfiore): ?string
    {
        self::$places ??= json_decode(file_get_contents(__DIR__.'/../../../database/data/codici-catastali.json'), true);

        return self::$places[$belfiore] ?? null;
    }
}
