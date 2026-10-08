<?php

namespace App\Services\Loans;

use App\Models\Company;
use App\Models\LoanRequest;
use App\Models\QuoteContractType;
use App\Models\QuoteSimulation;
use App\Services\Loans\Mediafacile\MediafacileClient;
use App\Services\Loans\Mediafacile\ScenarioBuilder;

/**
 * Importo erogato minimo e massimo dal servizio di simulazione Mediafacile: due chiamate,
 * una con le condizioni migliori (massimo) e una con le peggiori (minimo). Registra ogni simulazione.
 * Solo per i prodotti del catalogo (quinto, personale): gli altri usano ancora la simulazione casuale.
 */
class MediafacileLoanEstimator implements LoanEstimator
{
    public function __construct(private ScenarioBuilder $scenarios, private MediafacileClient $client, private RandomLoanEstimator $random) {}

    public function estimate(LoanRequest $loan): array
    {
        // I prodotti che il servizio non gestisce (finalizzato, mutuo...) restano alla simulazione, in attesa di altri driver.
        if (! QuoteContractType::where('product', $loan->product)->exists()) {
            return $this->random->estimate($loan);
        }

        $company = Company::forWhatsApp($loan->agent_wa_number);
        if (! $company?->hasQuoteCrm() || blank($company->preventivatore_passkey)) {
            throw new QuoteUnavailable('Preventivatore non configurato.');
        }

        $erogato = [];
        foreach ($this->scenarios->build($loan) as $name => $params) {
            $raw = $this->client->request($company->url_preventivatore, $company->preventivatore_passkey, $params);
            $offers = MediafacileClient::parse($raw);
            $valid = collect($offers)->where('valid', true)->pluck('erogato');

            QuoteSimulation::create([
                'loan_request_id' => $loan->id, 'scenario' => $name, 'request' => $params, 'response' => $raw,
                'offers_count' => count($offers), 'erogato_min' => $valid->min(), 'erogato_max' => $valid->max(),
            ]);

            if ($valid->isEmpty()) {
                throw new QuoteUnavailable("Nessuna offerta valida nello scenario {$name}.");
            }
            $erogato[$name] = $name === 'best' ? $valid->max() : $valid->min();
        }

        return ['min' => (int) round(min($erogato)), 'max' => (int) round(max($erogato))];
    }
}
