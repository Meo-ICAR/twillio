<?php

namespace App\Services\Loans\Mediafacile;

use App\Services\Loans\QuoteUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Chiamata HTTP al servizio di simulazione e lettura della risposta XML.
 * Tracciato della richiesta (POST con i parametri nell'URL) e nomi degli elementi: ipotesi dalla specifica sommaria 3.8,
 * da verificare quando arriva il tracciato definitivo. È l'unico punto che dipende da quei dettagli.
 */
class MediafacileClient
{
    /** @param  array<string,string>  $params  senza Passkey */
    public function request(string $url, string $passkey, array $params): string
    {
        try {
            $response = Http::timeout((int) config('finanziamento.quote.timeout', 15))->withQueryParameters(['Passkey' => $passkey] + $params)->post($url);
        } catch (ConnectionException $e) {
            throw new QuoteUnavailable('Servizio di simulazione non raggiungibile.', 0, $e);
        }

        if ($response->failed()) {
            throw new QuoteUnavailable("Il servizio di simulazione ha risposto {$response->status()}.");
        }

        return $response->body();
    }

    /** @return list<array{valid: bool, erogato: float}> una voce per ogni elemento che contiene Importo_erogato */
    public static function parse(string $raw): array
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($raw);
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            throw new QuoteUnavailable('Risposta del servizio di simulazione non valida.');
        }

        $offers = [];
        foreach ($xml->xpath('//Importo_erogato/..') ?: [] as $node) {
            $offers[] = ['valid' => trim((string) $node->Errore) === '2', 'erogato' => self::number((string) $node->Importo_erogato)];
        }

        return $offers;
    }

    /** «12.345,67» (italiano) oppure «12345.67». */
    private static function number(string $value): float
    {
        $value = trim($value);

        return (float) (str_contains($value, ',') ? str_replace(',', '.', str_replace('.', '', $value)) : $value);
    }
}
