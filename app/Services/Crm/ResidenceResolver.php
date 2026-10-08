<?php

namespace App\Services\Crm;

use Illuminate\Support\Str;

/**
 * Città e provincia dall'indirizzo di residenza («via, numero, CAP, città»), con l'elenco ISTAT dei comuni
 * già nel progetto. La provincia si omette se il nome è in più province senza sigla esplicita, o se il comune è sconosciuto.
 */
final class ResidenceResolver
{
    /** Nomi scritti in modo diverso dall'elenco ISTAT (chiavi normalizzate). */
    private const ALIASES = ['reggio emilia' => 'reggio nell emilia'];

    private const MAX_WORDS = 6;

    /** @var array<string,array{name: string, provinces: list<string>}>|null nome normalizzato => comune */
    private static ?array $index = null;

    /** @return array{city: string, province: ?string} */
    public static function resolve(string $address): array
    {
        $parts = array_map('trim', explode(',', $address));
        $segment = (string) end($parts);
        if ($segment === '') {
            return ['city' => '', 'province' => null];
        }

        [$text, $explicit] = self::splitProvince($segment);
        $words = explode(' ', self::normalize($text));

        for ($n = min(self::MAX_WORDS, count($words)); $n >= 1; $n--) {
            $key = implode(' ', array_slice($words, -$n));
            $place = self::index()[self::ALIASES[$key] ?? $key] ?? null;
            if (! $place) {
                continue;
            }

            $provinces = $place['provinces'];
            $province = $explicit && in_array($explicit, $provinces, true) ? $explicit : (count($provinces) === 1 ? $provinces[0] : null);

            return ['city' => $place['name'], 'province' => $province];
        }

        return ['city' => $text, 'province' => $explicit && in_array($explicit, self::provinces(), true) ? $explicit : null];
    }

    /** «Roma (RM)» o «Roma RM» → [«Roma», «RM»]; senza sigla valida → [testo, null]. */
    private static function splitProvince(string $segment): array
    {
        if (preg_match('/^(.*?)\s*\(?\b([A-Za-z]{2})\b\)?$/u', $segment, $m) && in_array(strtoupper($m[2]), self::provinces(), true) && trim($m[1]) !== '') {
            return [trim($m[1]), strtoupper($m[2])];
        }

        return [$segment, null];
    }

    private static function normalize(string $text): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower(Str::ascii($text))));
    }

    /** @return list<string> */
    private static function provinces(): array
    {
        return array_values(array_unique(array_merge(...array_map(fn ($p) => $p['provinces'], array_values(self::index())))));
    }

    /** @return array<string,array{name: string, provinces: list<string>}> */
    private static function index(): array
    {
        if (self::$index === null) {
            $index = [];
            foreach (json_decode((string) file_get_contents(__DIR__.'/../../../database/data/codici-catastali.json'), true) as $label) {
                if (! preg_match('/^(.*) \(([A-Z]{2})\)$/', $label, $m)) {
                    continue;
                }
                $key = self::normalize($m[1]);
                $index[$key]['name'] ??= $m[1];
                if (! in_array($m[2], $index[$key]['provinces'] ?? [], true)) {
                    $index[$key]['provinces'][] = $m[2];
                }
            }
            self::$index = $index;
        }

        return self::$index;
    }
}
