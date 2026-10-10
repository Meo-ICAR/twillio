<?php

namespace App\Services\Crm;

use App\Models\Attachment;
use App\Models\Company;
use App\Models\LoanRequest;
use App\Services\Crm\Capabilities\SendsDocuments;
use App\Services\Crm\Unicoloan\UnicoloanClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Consegna la richiesta a unicoloan (API /api/agente/v1): prima cliente e pratica (submit, sincrono), poi i documenti
 * (sendDocuments, da un job in coda). Gli invii sono idempotenti per codice della richiesta: ripeterli non duplica nulla.
 * Nei log solo il tipo di errore e i codici: mai dati personali.
 */
class UnicoloanGateway implements CrmGateway, SendsDocuments
{
    private UnicoloanClient $client;

    public function __construct(private Company $company)
    {
        $this->client = new UnicoloanClient($company);
    }

    public function submit(LoanRequest $loan, array $personal): int
    {
        if (! $this->client->isConfigured()) {
            Log::warning('unicoloan: indirizzo, token o segreto mancanti', ['loan' => $loan->code]);

            return 0;
        }

        $payload = SubmissionPayload::build($loan, $personal);

        if (blank($payload['cliente']['codice_fiscale'] ?? null) || blank($payload['agente']['partita_iva'] ?? null)) {
            Log::warning('unicoloan: codice fiscale del cliente o partita IVA dell\'agente mancanti', ['loan' => $loan->code]);

            return 422;
        }

        try {
            $response = $this->client->json('POST', '/api/agente/v1/richieste', $payload);
        } catch (ConnectionException) {
            Log::error('unicoloan: servizio non raggiungibile', ['loan' => $loan->code]);

            return 0;
        }

        if (! $response->successful()) {
            Log::warning('unicoloan: richiesta rifiutata', ['loan' => $loan->code, 'status' => $response->status(), 'codice' => $response->json('codice')]);

            return $response->status() ?: 502;
        }

        $loan->update(['crm_lead_id' => $response->json('codice_pratica')]);

        return 200;
    }

    /** Consegna i file non ancora inviati (esclusi quelli rifiutati); i già consegnati si saltano. */
    public function sendDocuments(LoanRequest $loan): int
    {
        if (! $this->client->isConfigured()) {
            return 0;
        }

        $disk = Storage::disk('local');
        $worst = 200;

        $pending = $loan->attachments()->with('praticaDocument')->whereNull('crm_sent_at')->orderBy('id')->get();

        foreach ($pending as $attachment) {
            if ($attachment->praticaDocument?->status === 'rejected' || ! $disk->exists($attachment->path)) {
                continue;
            }

            $status = $this->sendOne($loan, $attachment, $disk->get($attachment->path));

            if ($status === 200) {
                $attachment->forceFill(['crm_sent_at' => now()])->save();
            } else {
                $worst = $status;
            }
        }

        return $worst;
    }

    private function sendOne(LoanRequest $loan, Attachment $attachment, string $contents): int
    {
        $fields = array_filter([
            'tipo' => $attachment->praticaDocument?->code ?? $attachment->kind,
            'id_origine' => (string) $attachment->id,
            'analisi' => json_encode($this->analysis($attachment)),
        ]);

        try {
            $response = $this->client->upload(
                "/api/agente/v1/richieste/{$loan->code}/documenti",
                $fields,
                "{$attachment->kind}-{$attachment->id}.".(pathinfo($attachment->path, PATHINFO_EXTENSION) ?: 'bin'),
                $contents,
            );
        } catch (ConnectionException) {
            Log::error('unicoloan: servizio non raggiungibile (documento)', ['loan' => $loan->code, 'attachment' => $attachment->id]);

            return 0;
        }

        if (! $response->successful()) {
            Log::warning('unicoloan: documento rifiutato', ['loan' => $loan->code, 'attachment' => $attachment->id, 'status' => $response->status(), 'codice' => $response->json('codice')]);

            return $response->status() ?: 502;
        }

        return 200;
    }

    /**
     * Esito dell'analisi già fatta qui, per non rifarla a monte. Di default solo esito, tipo e discrepanze: i dati letti dal
     * documento si mandano soltanto se l'azienda sceglie `analysis = full`.
     *
     * @return array<string,mixed>
     */
    private function analysis(Attachment $attachment): array
    {
        $analysis = (array) $attachment->analysis;

        return array_filter([
            'esito' => $attachment->status,
            'tipo' => $attachment->kind,
            'discrepanze' => $analysis['discrepancies'] ?? null,
            'campi' => $this->client->config('analysis') === 'full' ? ($analysis['fields'] ?? null) : null,
            'modello' => $attachment->ai_model,
        ], fn ($value) => $value !== null && $value !== [] && $value !== '');
    }
}
