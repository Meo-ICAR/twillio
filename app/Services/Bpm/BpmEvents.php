<?php

namespace App\Services\Bpm;

use App\Jobs\SendBpmEvent;
use App\Models\Fornitore;
use App\Models\LoanRequest;

/**
 * Eventi che unicoagent comunica a UnicoBPM (`POST /api/events`): al perfezionamento di una pratica parte `pratica.perfezionata`,
 * così il processo di presa in carico dell'istruttore nasce subito e sollecita finché qualcuno la prende. Il `event_id` è fisso per
 * pratica ed evento: un nuovo invio non avvia due processi. Dati essenziali, mai contenuti di file né dati del cliente oltre al riferimento.
 */
class BpmEvents
{
    public static function isConfigured(): bool
    {
        return filled(config('services.bpm.url')) && filled(config('services.bpm.key'));
    }

    /** @return array<string,mixed> */
    public static function perfected(LoanRequest $loan): array
    {
        $agent = Fornitore::findByWhatsApp((string) $loan->agent_wa_number);

        return [
            'event_id' => "pratica.perfezionata:{$loan->code}",
            'event' => 'pratica.perfezionata',
            'source' => 'unicoagent',
            'subject_ref' => $loan->code,
            'occurred_at' => ($loan->perfected_at ?? now())->toIso8601String(),
            'data' => [
                'prodotto' => $loan->product,
                'prodotto_etichetta' => LoanRequest::productLabels($loan->company_id)[$loan->product] ?? $loan->product,
                'codice_pratica_crm' => $loan->crm_lead_id,
                'agente' => ['nome' => $agent?->name, 'partita_iva' => $agent?->piva],
                'ai_usage' => self::aiUsage($loan),
            ],
        ];
    }

    /**
     * Consumo dell'AI per leggere i documenti della pratica (solo totali, mai contenuti): UnicoBPM lo registra nel trace della chiamata.
     *
     * @return array{model: ?string, input_tokens: int, output_tokens: int, cost: float, readings: int}
     */
    public static function aiUsage(LoanRequest $loan): array
    {
        $read = $loan->attachments()->whereNotNull('ai_input_tokens')->get(['ai_model', 'ai_input_tokens', 'ai_output_tokens', 'ai_cost']);

        return [
            'model' => $read->pluck('ai_model')->filter()->last(),
            'input_tokens' => (int) $read->sum('ai_input_tokens'),
            'output_tokens' => (int) $read->sum('ai_output_tokens'),
            'cost' => round((float) $read->sum('ai_cost'), 6),
            'readings' => $read->count(),
        ];
    }

    /** Mette in coda la comunicazione (con ritentativi) se UnicoBPM è configurato e la pratica non è di prova. */
    public static function dispatchPerfected(LoanRequest $loan): void
    {
        if (! self::isConfigured() || $loan->is_test) {
            return;
        }

        SendBpmEvent::dispatch(self::perfected($loan));
    }
}
