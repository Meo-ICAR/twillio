<?php

namespace App\Services\Loans\Mediafacile;

use App\Models\LoanRequest;
use App\Models\QuoteBandBound;
use App\Models\QuoteContractType;
use App\Models\QuoteDuration;
use App\Models\QuoteEmploymentMap;
use App\Models\QuoteEmploymentType;
use App\Services\Loans\QuoteUnavailable;
use Carbon\CarbonImmutable;

/**
 * Dalle risposte a fasce della richiesta ai parametri del servizio di simulazione, per due scenari:
 * `best` (giovane, molta anzianità, reddito alto) e `worst` (anziano, poca anzianità, reddito basso).
 * Non include la Passkey. Lancia QuoteUnavailable se la richiesta non si può simulare.
 */
class ScenarioBuilder
{
    private const INCOME_NODES = ['pensionato' => 'pensione_netta', 'autonomo' => 'reddito_autonomo'];

    /** @return array{best: array<string,string>, worst: array<string,string>} */
    public function build(LoanRequest $loan): array
    {
        $answers = $loan->answers ?? [];
        $contract = QuoteContractType::where('product', $loan->product)->first()
            ?? throw new QuoteUnavailable("Il prodotto {$loan->product} non si simula.");

        $employment = $this->employmentType($answers, $contract->value);
        $months = $this->duration($contract->value, $answers['durata'] ?? null);
        $now = now()->toImmutable();

        return [
            'best' => $this->scenario($answers, $contract->value, $employment, $months, $now, best: true),
            'worst' => $this->scenario($answers, $contract->value, $employment, $months, $now, best: false),
        ];
    }

    /** @return array<string,string> */
    private function scenario(array $answers, string $contract, string $employment, int $months, CarbonImmutable $now, bool $best): array
    {
        $age = $this->bound('eta', $answers['eta'] ?? null, low: $best);
        $birth = $now->setDate($now->year - $age, 1, 1)->startOfDay();

        $seniorityCode = $answers['anzianita'] ?? $answers['anni_attivita'] ?? null;
        $seniority = $seniorityCode !== null
            ? $this->bound('anzianita', $seniorityCode, low: ! $best)
            : (int) config('finanziamento.quote.default_seniority_years', 20);
        $hired = max($now->setDate($now->year - $seniority, 1, 1)->startOfDay(), $birth->addYears(18));

        $income = $this->bound('reddito', $answers[self::INCOME_NODES[$answers['lavoro'] ?? ''] ?? 'reddito'] ?? null, low: ! $best);

        $common = [
            'Data_nascita' => $this->date($birth),
            'Data_assunzione' => $this->date($hired),
        ];

        if ($contract === 'Prestito') {
            return $common + [
                'Sesso' => $this->sex($answers),
                'Tipo_contratto' => $contract,
                'Tipo_rapporto' => $employment,
                'Durata' => (string) $months,
                'Importo_richiesto' => $this->money($this->bound('importo', $answers['importo'] ?? null, low: ! $best)),
                'Reddito_richiedenti' => $this->money($income),
            ];
        }

        return $common + [
            'Data_decorrenza' => $this->date($now->addMonthsNoOverflow(2)),
            'Sesso' => $this->sex($answers),
            'Tipo_contratto' => $contract,
            'Tipo_rapporto' => $employment,
            'Durata' => (string) $months,
            'Importo_rata' => $this->money($income / 5),
            'Rinnovo' => 'NO',
        ];
    }

    private function employmentType(array $answers, string $contract): string
    {
        $type = QuoteEmploymentMap::where('lavoro', $answers['lavoro'] ?? '')
            ->where(fn ($q) => $q->whereNull('ente_pensione')->orWhere('ente_pensione', $answers['ente_pensione'] ?? ''))
            ->where(fn ($q) => $q->whereNull('dimensione_azienda')->orWhere('dimensione_azienda', $answers['dimensione_azienda'] ?? ''))
            ->orderByDesc('priority')->value('tipo_rapporto')
            ?? throw new QuoteUnavailable('Situazione lavorativa non riconosciuta.');

        $allowed = QuoteEmploymentType::where('value', $type)->first()?->contracts;
        if ($allowed !== null && ! in_array($contract, $allowed, true)) {
            throw new QuoteUnavailable("{$type} non è ammesso con {$contract}.");
        }

        return $type;
    }

    private function duration(string $contract, ?string $code): int
    {
        if ($code === null || ! preg_match('/^m(\d+)$/', $code, $m)) {
            throw new QuoteUnavailable('Durata mancante.');
        }

        $wanted = (int) $m[1];
        $closest = QuoteDuration::where('contract', $contract)->orderBy('months')->pluck('months')
            ->sortBy(fn ($months) => abs($months - $wanted))->first();

        return $closest ?? throw new QuoteUnavailable("Nessuna durata ammessa per {$contract}.");
    }

    /** Estremo basso o alto della fascia (anni o euro). */
    private function bound(string $dimension, ?string $code, bool $low): int
    {
        $band = $code !== null ? QuoteBandBound::where('dimension', $dimension)->where('code', $code)->first() : null;

        return (int) ($band?->{$low ? 'low' : 'high'} ?? throw new QuoteUnavailable("Fascia {$dimension} mancante o sconosciuta."));
    }

    private function sex(array $answers): string
    {
        return ($answers['sesso'] ?? null) === 'sesso_f' ? 'F' : (($answers['sesso'] ?? null) === 'sesso_m' ? 'M' : (string) config('finanziamento.quote.default_sex', 'M'));
    }

    private function date(CarbonImmutable $date): string
    {
        return $date->format('m-d-Y');
    }

    private function money(float|int $value): string
    {
        return number_format($value, 2, ',', '');
    }
}
