<?php

namespace App\Services\Energy;

use App\Models\EnergySupplier;
use Illuminate\Support\Str;

/**
 * Riconosce il fornitore scritto dall'agente tra i venditori ARERA, senza badare a maiuscole, punteggiatura e forma giuridica.
 * Esiti: found (un solo venditore), many (più candidati: l'agente deve precisare), none, other («altro»: fornitore non in elenco).
 */
class EnergySupplierMatcher
{
    public const MAX_CANDIDATES = 5;

    private const MIN_LENGTH = 3;

    /** Parole che non distinguono un venditore dall'altro. */
    private const NOISE = ['spa', 'srl', 'srls', 'snc', 'sas', 'sapa', 'scarl', 'societa', 'benefit', 'unico', 'socio', 'di', 'la', 'il', 'e'];

    /** @return array{status: string, supplier?: EnergySupplier, candidates?: list<EnergySupplier>} */
    public function match(string $input): array
    {
        $key = self::normalize($input);

        if ($key === 'altro') {
            return ['status' => 'other'];
        }
        if (mb_strlen($key) < self::MIN_LENGTH) {
            return ['status' => 'none'];
        }

        $tokens = explode(' ', $key);
        $found = EnergySupplier::where('name_key', 'like', '%'.$tokens[0].'%')->get()
            ->filter(fn (EnergySupplier $s) => ! array_diff($tokens, explode(' ', $s->name_key)))
            ->values();

        $exact = $found->where('name_key', $key);
        if ($exact->count() === 1) {
            return ['status' => 'found', 'supplier' => $exact->first()];
        }
        if ($found->count() === 1) {
            return ['status' => 'found', 'supplier' => $found->first()];
        }
        if ($found->isEmpty()) {
            return ['status' => 'none'];
        }

        $ranked = $found->sortBy([
            fn (EnergySupplier $a, EnergySupplier $b) => (int) str_starts_with($b->name_key, $key) <=> (int) str_starts_with($a->name_key, $key),
            fn (EnergySupplier $a, EnergySupplier $b) => strlen($a->name_key) <=> strlen($b->name_key),
        ])->take(self::MAX_CANDIDATES)->values()->all();

        return ['status' => 'many', 'candidates' => $ranked];
    }

    /** «ENEL ENERGIA S.P.A.» → «enel energia». */
    public static function normalize(string $name): string
    {
        $text = Str::lower(Str::ascii($name));
        $text = str_replace('.', '', $text);
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text);
        $words = array_filter(explode(' ', $text), fn ($w) => $w !== '' && ! in_array($w, self::NOISE, true));

        return implode(' ', $words);
    }
}
