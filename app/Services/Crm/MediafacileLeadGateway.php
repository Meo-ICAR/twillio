<?php

namespace App\Services\Crm;

use App\Models\Company;
use App\Models\LoanRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Carica il lead sul CRM con il servizio Mediafacile (GET con i parametri nell'URL, risposta XML).
 * Restituisce 200 solo se `Stato` comincia con «OK»; ogni altro esito è un errore (l'agente può riprovare).
 * Tracciato della risposta (elementi `Stato` e `IDUU`): dalla specifica 1.6, da verificare. Nei log solo il tipo di errore.
 */
class MediafacileLeadGateway implements CrmGateway
{
    public function submit(LoanRequest $loan, array $personal): int
    {
        $company = Company::forWhatsApp($loan->agent_wa_number);
        if (! $company?->hasSubmissionCrm() || blank($company->istruttoria_passkey)) {
            Log::warning('Lead CRM: URL o passkey mancanti', ['loan' => $loan->code]);

            return 0;
        }

        try {
            $response = Http::timeout((int) config('finanziamento.quote.timeout', 15))
                ->withQueryParameters(['Passkey' => $company->istruttoria_passkey] + LeadParameters::build($loan, $personal))
                ->get($company->url_istruttoria);
        } catch (ConnectionException) {
            Log::error('Lead CRM: servizio non raggiungibile', ['loan' => $loan->code]);

            return 0;
        }

        if ($response->failed()) {
            Log::error('Lead CRM: risposta HTTP', ['loan' => $loan->code, 'status' => $response->status()]);

            return $response->status();
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response->body());
        libxml_use_internal_errors($previous);

        $stato = $xml ? trim((string) ($xml->xpath('//Stato')[0] ?? '')) : '';
        if (! str_starts_with(strtoupper($stato), 'OK')) {
            Log::warning('Lead CRM: caricamento non riuscito', ['loan' => $loan->code, 'ok' => false]);

            return 422;
        }

        $loan->update(['crm_lead_id' => trim((string) ($xml->xpath('//IDUU')[0] ?? '')) ?: null]);

        return 200;
    }
}
