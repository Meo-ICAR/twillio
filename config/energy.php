<?php

// Alberi di GBD Energy (Edison): simulazione e perfezionamento. Solo dati, nessuna closure (compatibile con config:cache).
// Limiti WhatsApp: titolo opzione max 24 caratteri, max 10 opzioni per nodo.

$yn = ['si' => 'Sì', 'no' => 'No'];
$numero = '/^\d{1,3}(\.\d{3})*(,\d+)?$|^\d+([.,]\d+)?$/';

$choice = fn (string $label, string $prompt, array $options, string|array $next, array $extra = []) => array_merge(
    ['type' => 'choice', 'label' => $label, 'prompt' => $prompt, 'options' => $options, 'next' => $next], $extra
);
$text = fn (string $label, string $prompt, array $rules, string|array $next, array $extra = []) => array_merge(
    ['type' => 'text', 'label' => $label, 'prompt' => $prompt, 'rules' => $rules, 'next' => $next], $extra
);
$summary = fn (array $extra = []) => array_merge([
    'type' => 'summary', 'prompt' => 'Confermi i dati inseriti?', 'save' => false,
    'options' => ['conferma' => 'Conferma', 'modifica' => 'Ricomincia', 'annulla' => 'Annulla'],
], $extra);

return [
    'flows' => [
        // Simulazione: consumi attuali → il preventivatore consiglia l'offerta più conveniente.
        'richiesta' => [
            'start' => 'prodotto',
            'restart' => 'prodotto',
            'nodes' => [
                'prodotto' => $choice('Fornitura', 'Che fornitura vuoi proporre al cliente?', ['luce' => 'Luce', 'gas' => 'Gas'], 'consumo'),
                'consumo' => $text('Consumo annuo', 'Consumo annuo del cliente (kWh per la luce, Smc per il gas), dall\'ultima bolletta:', ['required', 'regex:'.$numero], 'spesa_attuale', ['error' => 'Scrivi un numero, per esempio 2700 o 2.700,5.']),
                'spesa_attuale' => $text('Spesa annua attuale', 'Quanto spende oggi il cliente in un anno, in euro? (se non lo sai scrivi 0)', ['required', 'regex:'.$numero], 'riepilogo', ['error' => 'Scrivi un numero, per esempio 650.']),
                'riepilogo' => $summary(),
            ],
        ],

        // Sottoscrizione: dati del cliente e della fornitura; chiude con la mail all'istruttoria di GBD.
        'perfezionamento' => [
            'start' => 'codice',
            'restart' => 'codice_fiscale',
            'nodes' => [
                'codice' => ['type' => 'code', 'prompt' => 'Inserisci il codice della pratica:', 'save' => false, 'next' => 'conferma_pratica'],
                'conferma_pratica' => $choice('Conferma', 'È la pratica giusta?', $yn, ['si' => 'codice_fiscale', 'no' => 'codice'], ['save' => false, 'prompt_summary' => true]),
                'codice_fiscale' => $text('Codice fiscale', 'Codice fiscale del cliente:', ['required', 'regex:/^[A-Z]{6}[0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{3}[A-Z]$/'], 'cognome', [
                    'upper' => true, 'strip_spaces' => true, 'error' => 'Codice fiscale non valido (16 caratteri), riprova.', 'checks' => ['codice_fiscale'],
                ]),
                'cognome' => $text('Cognome', 'Cognome del cliente:', ['required', 'string', 'max:60'], 'nome'),
                'nome' => $text('Nome', 'Nome del cliente:', ['required', 'string', 'max:60'], 'residenza'),
                'residenza' => $text('Residenza', 'Indirizzo di residenza (via, numero, CAP, città):', ['required', 'string', 'max:160'], 'indirizzo_fornitura'),
                'indirizzo_fornitura' => $text('Indirizzo di fornitura', 'Indirizzo di fornitura (se è uguale alla residenza, ripetilo):', ['required', 'string', 'max:160'], 'pod_pdr'),
                'pod_pdr' => $text('POD / PDR', 'Codice POD (luce, inizia con IT) o PDR (gas, 14 cifre) dalla bolletta:', ['required', 'string', 'max:20'], 'fornitore_attuale', ['upper' => true, 'strip_spaces' => true]),
                'fornitore_attuale' => $text('Fornitore attuale', 'Chi è il fornitore attuale?', ['required', 'string', 'max:60'], 'telefono'),
                'telefono' => $text('Telefono', 'Cellulare del cliente:', ['required', 'regex:/^\+?\d{8,15}$/'], 'email', ['strip_spaces' => true, 'error' => 'Numero non valido, riprova.']),
                'email' => $text('Email', 'Email del cliente:', ['required', 'email'], 'contatto_diretto', ['error' => 'Email non valida, riprova.']),
                'contatto_diretto' => $choice('Contatto diretto', 'Il cliente acconsente a essere ricontattato da GBD Energy per il contratto?', $yn, 'riepilogo'),
                'riepilogo' => $summary(),
            ],
        ],
    ],
];
