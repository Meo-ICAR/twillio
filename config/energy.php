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
    // Parametri DIMOSTRATIVI del preventivatore. Prezzo di riferimento «tutto compreso» (€/kWh, €/Smc) con cui la spesa mensile
    // diventa un consumo annuo; `split` ripartisce la spesa unica di «Luce e Gas».
    'quote' => [
        'reference' => [
            'privato' => ['luce' => 0.28, 'gas' => 1.10],
            'bt' => ['luce' => 0.24, 'gas' => 0.95],
            'mt' => ['luce' => 0.17, 'gas' => 0.70],
        ],
        'split' => ['luce' => 0.6, 'gas' => 0.4],
    ],

    'flows' => [
        // Simulazione sul modello dell'IVR Edison: profilo, fornitura, residenza o tensione, spesa mensile → offerta più conveniente.
        'richiesta' => [
            'start' => 'profilo',
            'restart' => 'profilo',
            'nodes' => [
                'profilo' => $choice('Profilo cliente', 'Che tipo di utenza è?', ['privato' => 'Abitazione privata', 'azienda' => 'Azienda o P.IVA'], 'prodotto'),
                'prodotto' => $choice('Fornitura', 'Che fornitura vuoi proporre al cliente?', ['luce' => 'Luce', 'gas' => 'Gas', 'luce_gas' => 'Luce e Gas'], ['privato' => 'residenza', 'azienda' => 'tensione'], ['next_by' => 'profilo']),
                'residenza' => $choice('Residenza', 'La fornitura è per l\'abitazione di residenza principale?', ['principale' => 'Residenza principale', 'seconda' => 'Seconda casa'], 'spesa_mensile'),
                'tensione' => $choice('Tipo di fornitura', 'La fornitura è in bassa o media tensione? Bassa: uffici, negozi, piccoli laboratori. Media: impianti industriali.', ['bt' => 'Bassa tensione', 'mt' => 'Media tensione'], 'spesa_mensile'),
                'spesa_mensile' => $text('Spesa mensile', 'Spesa media mensile del cliente in euro (per luce e gas insieme, il totale):', ['required', 'regex:'.$numero], 'riepilogo', ['error' => 'Scrivi un numero, per esempio 80 o 350.']),
                'riepilogo' => $summary(),
            ],
        ],

        // Sottoscrizione: privato o azienda, dati del cliente e della fornitura; chiude con la mail all'istruttoria di GBD.
        // Per un'azienda il codice fiscale e il nome sono quelli del legale rappresentante che firma.
        'perfezionamento' => [
            'start' => 'codice',
            'restart' => 'tipo_cliente',
            'labels' => ['data_nascita' => 'Data di nascita', 'sesso' => 'Sesso', 'fornitore_attuale_nome' => 'Fornitore attuale (ragione sociale)', 'fornitore_attuale_piva' => 'Fornitore attuale (P.IVA)'],
            'nodes' => [
                'codice' => ['type' => 'code', 'prompt' => 'Inserisci il codice della pratica:', 'save' => false, 'next' => 'conferma_pratica'],
                'conferma_pratica' => $choice('Conferma', 'È la pratica giusta?', $yn, ['si' => 'tipo_cliente', 'no' => 'codice'], ['save' => false, 'prompt_summary' => true]),
                'tipo_cliente' => $choice('Tipo di cliente', 'Il cliente è un privato o un\'azienda?', ['privato' => 'Privato', 'azienda' => 'Azienda'], ['privato' => 'codice_fiscale', 'azienda' => 'ragione_sociale']),
                'ragione_sociale' => $text('Ragione sociale', 'Ragione sociale dell\'azienda:', ['required', 'string', 'max:120'], 'partita_iva'),
                'partita_iva' => $text('Partita IVA', 'Partita IVA dell\'azienda (11 cifre):', ['required', 'regex:/^(IT)?\d{11}$/'], 'codice_fiscale', [
                    'upper' => true, 'strip_spaces' => true, 'error' => 'Partita IVA non valida (11 cifre), riprova.', 'checks' => ['partita_iva'],
                ]),
                'codice_fiscale' => $text('Codice fiscale', 'Codice fiscale del cliente (per un\'azienda, del legale rappresentante):', ['required', 'regex:/^[A-Z]{6}[0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{3}[A-Z]$/'], 'cognome', [
                    'upper' => true, 'strip_spaces' => true, 'error' => 'Codice fiscale non valido (16 caratteri), riprova.',
                    // Ricava data, sesso e luogo di nascita; poi controlla l'età sulla data ricavata.
                    'checks' => ['codice_fiscale', ['name' => 'maggiorenne', 'campo' => 'data_nascita', 'anni' => 18, 'messaggio' => 'Dal codice fiscale la persona risulta minorenne: controlla il codice.']],
                ]),
                'cognome' => $text('Cognome', 'Cognome (del cliente o del legale rappresentante):', ['required', 'string', 'max:60'], 'nome', ['show_derived' => true]),
                'nome' => $text('Nome', 'Nome (del cliente o del legale rappresentante):', ['required', 'string', 'max:60'], 'verifica_cf'),
                'verifica_cf' => [
                    'type' => 'check', 'check' => 'cf_names', 'prompt' => 'Cognome e nome coerenti con il codice fiscale?', 'save' => false,
                    'next' => ['ok' => 'luogo_nascita', 'mismatch' => 'conferma_cf'], 'outcomes' => ['ok' => 'Coerenti', 'mismatch' => 'Non coerenti'],
                ],
                'conferma_cf' => $choice('Conferma codice fiscale', 'Confermi il codice fiscale inserito?', ['cf_ok' => 'Confermo il codice', 'cf_no' => 'Lo reinserisco'], ['cf_ok' => 'luogo_nascita', 'cf_no' => 'codice_fiscale'], ['save' => false, 'show_difformita' => true, 'reask' => ['cf_no' => 'codice_fiscale']]),
                'luogo_nascita' => $text('Luogo di nascita', 'Luogo di nascita (non ricavabile dal codice fiscale):', ['required', 'string', 'max:80'], 'residenza', ['skip_if' => 'filled:luogo_nascita']),
                'residenza' => $text('Residenza o sede', 'Indirizzo di residenza o sede legale (via, numero, CAP, città):', ['required', 'string', 'max:160'], 'fornitura_uguale'),
                'fornitura_uguale' => $choice('Fornitura nella stessa sede', 'L\'indirizzo di fornitura è lo stesso di residenza o sede?', ['si' => 'Sì, stesso indirizzo', 'no' => 'No, ne indico un altro'], ['si' => 'pod', 'no' => 'indirizzo_fornitura']),
                'indirizzo_fornitura' => $text('Indirizzo di fornitura', 'Indirizzo di fornitura (via, numero, CAP, città):', ['required', 'string', 'max:160'], 'pod'),
                // Si chiede il POD per la luce o il PDR per il gas, secondo la fornitura della pratica.
                'pod' => $text('POD', 'Codice POD della fornitura luce (IT + 3 cifre + E + 8 caratteri), dalla bolletta:', ['required', 'regex:/^IT\d{3}E[A-Z0-9]{8}$/'], 'pdr', ['upper' => true, 'strip_spaces' => true, 'skip_if' => 'product_not:luce', 'error' => 'POD non valido (esempio IT001E12345678), riprova.']),
                'pdr' => $text('PDR', 'Codice PDR della fornitura gas (14 cifre), dalla bolletta:', ['required', 'regex:/^\d{14}$/'], 'fornitore_attuale', ['strip_spaces' => true, 'skip_if' => 'product_not:gas', 'error' => 'PDR non valido (14 cifre), riprova.']),
                'fornitore_attuale' => $text('Fornitore attuale', 'Chi è il fornitore attuale? Scrivi il nome come in bolletta (es. Enel Energia). Se non lo trovi scrivi «altro».', ['required', 'string', 'max:80'], 'telefono', ['checks' => ['fornitore_energia']]),
                'telefono' => $text('Telefono', 'Cellulare del cliente (serve per la firma con OTP):', ['required', 'regex:/^\+?\d{8,15}$/'], 'email', ['strip_spaces' => true, 'error' => 'Numero non valido, riprova.', 'checks' => ['mobile']]),
                'email' => $text('Email', 'Email del cliente (riceverà il link per la firma):', ['required', 'email'], 'contatto_diretto', ['error' => 'Email non valida, riprova.', 'checks' => ['email']]),
                'contatto_diretto' => $choice('Contatto diretto', 'Il cliente acconsente a essere ricontattato da GBD Energy per il contratto?', $yn, 'riepilogo'),
                'riepilogo' => $summary(),
            ],
        ],
    ],
];
