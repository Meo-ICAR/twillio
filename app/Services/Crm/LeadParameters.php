<?php

namespace App\Services\Crm;

use App\Models\Fornitore;
use App\Models\LoanRequest;
use App\Models\QuoteBandBound;
use App\Models\QuoteEmploymentMap;
use Carbon\Carbon;

/**
 * Parametri del servizio di caricamento lead (specifica 1.6) dai dati della pratica perfezionata.
 * Non include la Passkey né il file: nessun documento va al CRM. I valori non ricavabili si omettono.
 */
final class LeadParameters
{
    /** @return array<string,string> */
    public static function build(LoanRequest $loan, array $personal): array
    {
        $answers = $loan->answers ?? [];
        $band = QuoteBandBound::where('dimension', 'importo')->where('code', $answers['importo'] ?? '')->first();
        $residence = ResidenceResolver::resolve((string) ($personal['residenza'] ?? ''));

        return array_filter([
            'cognome' => $personal['cognome'] ?? null,
            'nome' => $personal['nome'] ?? null,
            'data_nascita' => self::birthDate($personal['data_nascita'] ?? null),
            'tipologia' => QuoteEmploymentMap::resolve($answers)?->lead_tipologia,
            'importo_richiesto' => $band ? number_format($band->high, 2, ',', '') : null,
            'residenza_citta' => $residence['city'],
            'residenza_provincia' => $residence['province'],
            'cellulare' => $personal['telefono'] ?? null,
            'email' => $personal['email'] ?? null,
            'fonte' => (string) config('finanziamento.lead.fonte', 'unicoagent'),
            'annotazioni' => self::notes($loan, $answers, $band),
        ], fn ($value) => $value !== null && $value !== '');
    }

    /** «gg/mm/aaaa» → «mm-gg-aaaa»; null se non è una data valida. */
    private static function birthDate(?string $date): ?string
    {
        if (! $date || ! preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $date, $m) || ! checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return null;
        }

        return Carbon::createFromDate((int) $m[3], (int) $m[2], (int) $m[1])->format('m-d-Y');
    }

    private static function notes(LoanRequest $loan, array $answers, ?QuoteBandBound $band): string
    {
        $producer = Fornitore::findByWhatsApp((string) $loan->agent_wa_number)?->sigla;

        return implode(' · ', array_filter([
            "Pratica {$loan->code}",
            LoanRequest::productLabels()[$loan->product] ?? $loan->product,
            preg_match('/^m(\d+)$/', (string) ($answers['durata'] ?? ''), $m) ? "{$m[1]} mesi" : null,
            $band?->label ? "importo {$band->label}" : null,
            $producer ? "produttore {$producer}" : null,
        ]));
    }
}
