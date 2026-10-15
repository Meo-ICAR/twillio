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
$file = fn (string $kind, string $prompt, string|array $next, array $extra = []) => array_merge(
    ['type' => 'file', 'kind' => $kind, 'prompt' => $prompt, 'next' => $next, 'save' => false], $extra
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

        // Sottoscrizione: informativa, poi i documenti del cliente (ognuno si può saltare), che l'AI legge; dopo la conferma dei dati letti
        // il bot chiede solo ciò che manca. Privato o azienda arriva dal preventivo (`answers.profilo`): per un'azienda il codice fiscale
        // e il nome sono quelli del legale rappresentante che firma. Chiude con la mail all'istruttoria di GBD e l'invio in firma.
        'perfezionamento' => [
            'start' => 'codice',
            // «Ricomincia» riparte da qui: con i dati già confermati il nodo si salta e instrada per profilo.
            'restart' => 'rivedi_dati',
            'labels' => [
                'data_nascita' => 'Data di nascita', 'sesso' => 'Sesso', 'fornitore_attuale_nome' => 'Fornitore attuale (ragione sociale)',
                'fornitore_attuale_piva' => 'Fornitore attuale (P.IVA)', 'potenza' => 'Potenza impegnata (kW)', 'titolo_possesso' => 'Titolo di possesso',
                'destinazione_uso' => 'Destinazione d\'uso', 'pagamento' => 'Pagamento', 'intestatario_iban' => 'Intestatario del conto',
                'intestatario_conto' => 'Conto del richiedente', 'terzo_intestatario' => 'Terzo intestatario',
            ],
            'nodes' => [
                'codice' => ['type' => 'code', 'prompt' => 'Inserisci il codice della pratica:', 'save' => false, 'next' => 'conferma_pratica'],
                'conferma_pratica' => $choice('Conferma', 'È la pratica giusta?', $yn, ['si' => 'informativa', 'no' => 'codice'], ['save' => false, 'prompt_summary' => true]),
                'informativa' => $file('informativa', 'Per procedere invia l\'informativa privacy firmata dal cliente (foto o PDF).', 'doc_bolletta', ['skip_if' => 'privacy_received', 'analyze' => true, 'ack' => true]),

                // Documenti: l'AI li legge dopo (non subito) e propone i dati da confermare. «salta» passa al successivo.
                'doc_bolletta' => $file('bolletta', 'Invia la bolletta attuale del cliente (foto o PDF), oppure scrivi «salta».', 'doc_identita', ['skippable' => true, 'analyze' => true, 'ack' => true]),
                'doc_identita' => $file('documento_identita', 'Invia il documento d\'identità del cliente (foto o PDF), oppure scrivi «salta».', ['azienda' => 'doc_visura', '*' => 'doc_conto'], ['skippable' => true, 'analyze' => true, 'ack' => true, 'next_by' => 'profilo']),
                'doc_visura' => $file('visura', 'Invia la visura camerale dell\'azienda (foto o PDF), oppure scrivi «salta».', 'doc_conto', ['skippable' => true, 'analyze' => true, 'ack' => true]),
                'doc_conto' => $file('estratto_conto', 'Invia una foto dell\'estratto conto o dell\'IBAN del cliente, oppure scrivi «salta».', 'attesa_documenti', ['skippable' => true, 'analyze' => true, 'ack' => true]),
                'attesa_documenti' => ['type' => 'wait', 'prompt' => "⏳ Sto controllando i documenti: ti scrivo appena ho finito.\nSe non vuoi aspettare scrivi «avanti».", 'save' => false, 'next' => 'rivedi_dati'],
                'rivedi_dati' => ['type' => 'review', 'prompt' => 'I dati letti dai documenti sono corretti?', 'save' => false, 'next' => ['azienda' => 'partita_iva', '*' => 'codice_fiscale'], 'next_by' => 'profilo', 'skip_if' => 'no_proposals', 'options' => ['conferma' => 'Sì, confermo', 'correggi' => 'No, li inserisco io']],

                // Azienda: dalla partita IVA il Registro Imprese compila ragione sociale e sede (se non risponde si chiedono).
                'partita_iva' => $text('Partita IVA', 'Partita IVA dell\'azienda (11 cifre):', ['required', 'regex:/^(IT)?\d{11}$/'], 'ragione_sociale', [
                    'upper' => true, 'strip_spaces' => true, 'skip_if' => 'filled:partita_iva', 'error' => 'Partita IVA non valida (11 cifre), riprova.', 'checks' => ['partita_iva', 'registro_imprese'],
                ]),
                'ragione_sociale' => $text('Ragione sociale', 'Ragione sociale dell\'azienda:', ['required', 'string', 'max:120'], 'codice_fiscale', ['skip_if' => 'filled:ragione_sociale']),

                'codice_fiscale' => $text('Codice fiscale', 'Codice fiscale del cliente (per un\'azienda, del legale rappresentante):', ['required', 'regex:/^[A-Z]{6}[0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{3}[A-Z]$/'], 'cognome', [
                    'upper' => true, 'strip_spaces' => true, 'skip_if' => 'filled:codice_fiscale', 'error' => 'Codice fiscale non valido (16 caratteri), riprova.',
                    // Ricava data, sesso e luogo di nascita; poi controlla l'età sulla data ricavata.
                    'checks' => ['codice_fiscale', ['name' => 'maggiorenne', 'campo' => 'data_nascita', 'anni' => 18, 'messaggio' => 'Dal codice fiscale la persona risulta minorenne: controlla il codice.']],
                ]),
                'cognome' => $text('Cognome', 'Cognome (del cliente o del legale rappresentante):', ['required', 'string', 'max:60'], 'nome', ['show_derived' => true, 'skip_if' => 'filled:cognome']),
                'nome' => $text('Nome', 'Nome (del cliente o del legale rappresentante):', ['required', 'string', 'max:60'], 'verifica_cf', ['skip_if' => 'filled:nome']),
                'verifica_cf' => [
                    'type' => 'check', 'check' => 'cf_names', 'prompt' => 'Cognome e nome coerenti con il codice fiscale?', 'save' => false,
                    'next' => ['ok' => 'luogo_nascita', 'mismatch' => 'conferma_cf'], 'outcomes' => ['ok' => 'Coerenti', 'mismatch' => 'Non coerenti'],
                ],
                'conferma_cf' => $choice('Conferma codice fiscale', 'Confermi il codice fiscale inserito?', ['cf_ok' => 'Confermo il codice', 'cf_no' => 'Lo reinserisco'], ['cf_ok' => 'luogo_nascita', 'cf_no' => 'codice_fiscale'], ['save' => false, 'show_difformita' => true, 'reask' => ['cf_no' => 'codice_fiscale']]),
                'luogo_nascita' => $text('Luogo di nascita', 'Luogo di nascita (non ricavabile dal codice fiscale):', ['required', 'string', 'max:80'], 'residenza', ['skip_if' => 'filled:luogo_nascita']),
                'residenza' => $text('Residenza o sede', 'Indirizzo di residenza o sede legale (via, numero, CAP, città):', ['required', 'string', 'max:160'], 'fornitura_uguale', ['skip_if' => 'filled:residenza']),

                // Fornitura: ciò che la bolletta ha già dato non si richiede (POD/PDR secondo luce, gas o luce e gas).
                'fornitura_uguale' => $choice('Fornitura nella stessa sede', 'L\'indirizzo di fornitura è lo stesso di residenza o sede?', ['si' => 'Sì, stesso indirizzo', 'no' => 'No, ne indico un altro'], ['no' => 'indirizzo_fornitura', '*' => 'pod'], ['skip_if' => 'filled:indirizzo_fornitura']),
                'indirizzo_fornitura' => $text('Indirizzo di fornitura', 'Indirizzo di fornitura (via, numero, CAP, città):', ['required', 'string', 'max:160'], 'pod', ['skip_if' => 'filled:indirizzo_fornitura']),
                'pod' => $text('POD', 'Codice POD della fornitura luce (IT + 3 cifre + E + 8 caratteri), dalla bolletta:', ['required', 'regex:/^IT\d{3}E[A-Z0-9]{8}$/'], 'pdr', ['upper' => true, 'strip_spaces' => true, 'skip_if' => 'product_not:luce', 'error' => 'POD non valido (esempio IT001E12345678), riprova.']),
                'pdr' => $text('PDR', 'Codice PDR della fornitura gas (14 cifre), dalla bolletta:', ['required', 'regex:/^\d{14}$/'], 'potenza', ['strip_spaces' => true, 'skip_if' => 'product_not:gas', 'error' => 'PDR non valido (14 cifre), riprova.']),
                'potenza' => $text('Potenza impegnata', 'Potenza impegnata in kW (di solito 3 per le abitazioni):', ['required', 'regex:'.$numero], 'fornitore_attuale', ['skip_if' => 'filled:potenza', 'error' => 'Scrivi un numero, per esempio 3 o 4,5.']),
                'fornitore_attuale' => $text('Fornitore attuale', 'Chi è il fornitore attuale? Scrivi il nome come in bolletta (es. Enel Energia). Se non lo trovi scrivi «altro».', ['required', 'string', 'max:80'], 'pagamento', ['skip_if' => 'filled:fornitore_attuale', 'checks' => ['fornitore_energia']]),

                // Pagamento: addebito in conto (SDD) o bollettino/carta. Se l'intestatario non è il richiedente la pratica è segnata «terzo intestatario».
                'pagamento' => $choice('Pagamento', 'Come paga il cliente le bollette?', ['sdd' => 'Addebito in conto (SDD)', 'bollettino' => 'Bollettino o carta'], ['sdd' => 'iban', 'bollettino' => 'titolo_possesso']),
                'iban' => $text('IBAN', 'IBAN del conto per l\'addebito:', ['required', 'regex:/^IT\d{2}[A-Z0-9]{23}$/'], 'intestatario_conto', ['upper' => true, 'strip_spaces' => true, 'skip_if' => 'filled:iban', 'checks' => ['iban'], 'error' => 'IBAN non valido (formato o checksum errati), riprova.']),
                'intestatario_conto' => $choice('Conto del richiedente', 'Il conto è intestato al richiedente del contratto?', $yn, ['no' => 'intestatario_iban', '*' => 'titolo_possesso'], ['skip_if' => 'filled:intestatario_iban']),
                'intestatario_iban' => $text('Intestatario del conto', 'Nome e cognome dell\'intestatario del conto:', ['required', 'string', 'max:120'], 'titolo_possesso', ['skip_if' => 'filled:intestatario_iban']),

                // Dati che non stanno sui documenti.
                'titolo_possesso' => $choice('Titolo di possesso', 'Titolo di possesso dell\'immobile (D.L. 47/2014):', ['proprieta' => 'Proprietà', 'affitto' => 'Affitto / Locazione', 'comodato' => 'Comodato / Altro'], ['azienda' => 'telefono', '*' => 'destinazione_uso'], ['next_by' => 'profilo']),
                'destinazione_uso' => $choice('Destinazione d\'uso', 'Destinazione d\'uso della fornitura:', ['residenza' => 'Abitazione di residenza', 'seconda' => 'Seconda casa'], 'telefono', ['skip_if' => 'filled:destinazione_uso']),
                'telefono' => $text('Telefono', 'Cellulare del cliente (serve per la firma con OTP):', ['required', 'regex:/^\+?\d{8,15}$/'], 'email', ['strip_spaces' => true, 'error' => 'Numero non valido, riprova.', 'checks' => ['mobile']]),
                'email' => $text('Email', 'Email del cliente (riceverà il link per la firma e la bolletta digitale):', ['required', 'email'], 'contatto_diretto', ['error' => 'Email non valida, riprova.', 'checks' => ['email']]),
                'contatto_diretto' => $choice('Contatto diretto', 'Il cliente acconsente a essere ricontattato da GBD Energy per il contratto?', $yn, 'riepilogo'),
                'riepilogo' => $summary(['provenance' => true, 'prompt' => 'Invio il contratto al cliente per la firma?', 'options' => ['conferma' => 'Invia per la firma', 'modifica' => 'Ricomincia', 'annulla' => 'Annulla']]),
            ],
        ],
    ],
];
