<?php

namespace App\Services\Crm;

use App\Models\Fornitore;
use App\Models\LoanRequest;
use App\Models\QuoteBandBound;
use Carbon\Carbon;

/**
 * Formato unico con cui la pratica viene consegnata a un CRM che non ha un tracciato proprio (driver generico, unicoloan...).
 * Solo dati semplici: nessun contenuto di file. I driver con un tracciato fisso (Mediafacile) ne costruiscono uno loro.
 */
final class SubmissionPayload
{
    /** @return array<string,mixed> */
    public static function build(LoanRequest $loan, array $personal): array
    {
        $answers = $loan->answers ?? [];
        $band = QuoteBandBound::where('dimension', 'importo')->where('code', $answers['importo'] ?? '')->first();
        $residence = ResidenceResolver::resolve((string) ($personal['residenza'] ?? ''));
        $agent = Fornitore::findByWhatsApp((string) $loan->agent_wa_number);

        return [
            'riferimento' => $loan->code,
            'prodotto' => $loan->product,
            'prodotto_etichetta' => LoanRequest::productLabels()[$loan->product] ?? $loan->product,
            'risposte' => $answers,
            'pratica' => [
                'importo_richiesto' => $band ? (float) $band->high : null,
                'importo_fascia' => $band?->label,
                'durata_mesi' => preg_match('/^m(\d+)$/', (string) ($answers['durata'] ?? ''), $m) ? (int) $m[1] : null,
            ],
            'cliente' => [
                'cognome' => $personal['cognome'] ?? null,
                'nome' => $personal['nome'] ?? null,
                'codice_fiscale' => $personal['codice_fiscale'] ?? null,
                'data_nascita' => self::isoDate($personal['data_nascita'] ?? null),
                'luogo_nascita' => $personal['luogo_nascita'] ?? null,
                'telefono' => $personal['telefono'] ?? null,
                'email' => $personal['email'] ?? null,
                'residenza' => [
                    'indirizzo' => $personal['residenza'] ?? null,
                    'citta' => $residence['city'] ?? null,
                    'provincia' => $residence['province'] ?? null,
                ],
            ],
            'agente' => [
                'nome' => $agent?->name,
                'sigla' => $agent?->sigla,
                'partita_iva' => $agent?->piva,
                'whatsapp' => $loan->agent_wa_number,
            ],
            'consenso' => [
                'privacy_ricevuta_il' => $loan->privacy_received_at?->toIso8601String(),
                'privacy_verificata_il' => $loan->privacy_verified_at?->toIso8601String(),
                'contatto_diretto' => (bool) $loan->direct_contact,
            ],
            'documenti' => $loan->praticaDocuments()->with('attachments')->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn ($doc): array => [
                    'codice' => $doc->code,
                    'nome' => $doc->name,
                    'obbligo' => $doc->requirement,
                    'stato' => $doc->status,
                    'allegati' => $doc->attachments->map(fn ($a): array => [
                        'tipo' => $a->kind,
                        'mime' => $a->mime,
                        'ricevuto_il' => $a->received_at?->toIso8601String(),
                    ])->all(),
                ])->all(),
        ];
    }

    private static function isoDate(?string $date): ?string
    {
        if (! $date || ! preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $date, $m) || ! checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return null;
        }

        return Carbon::createFromDate((int) $m[3], (int) $m[2], (int) $m[1])->toDateString();
    }
}
