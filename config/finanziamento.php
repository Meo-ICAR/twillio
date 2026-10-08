<?php

use App\Services\Checks\CodiceFiscaleCheck;
use App\Services\Checks\DatiCoerentiCheck;
use App\Services\Checks\EstraiDatiCheck;
use App\Services\Checks\IbanCheck;
use App\Services\Checks\InformativaFirmataCheck;
use App\Services\Checks\MaggiorenneCheck;
use App\Services\Checks\TipoDocumentoCheck;

// Alberi delle conversazioni WhatsApp. Solo dati: nessuna closure salvata (compatibile con config:cache).
// Limiti WhatsApp: titolo opzione max 24 caratteri, max 10 opzioni per nodo.

$yn = ['si' => 'Sì', 'no' => 'No'];
$importi = ['imp_5k' => 'Fino a 5.000 €', 'imp_10k' => '5.000 - 10.000 €', 'imp_20k' => '10.000 - 20.000 €', 'imp_35k' => '20.000 - 35.000 €', 'imp_oltre' => 'Oltre 35.000 €'];
$grandi = ['g_100k' => 'Fino a 100.000 €', 'g_200k' => '100.000 - 200.000 €', 'g_400k' => '200.000 - 400.000 €', 'g_oltre' => 'Oltre 400.000 €'];
$redditi = ['red_1000' => 'Fino a 1.000 €', 'red_1500' => '1.000 - 1.500 €', 'red_2000' => '1.500 - 2.000 €', 'red_3000' => '2.000 - 3.000 €', 'red_oltre' => 'Oltre 3.000 €'];
$anzianita = ['anz_1' => 'Meno di 1 anno', 'anz_3' => '1 - 3 anni', 'anz_10' => '3 - 10 anni', 'anz_oltre' => 'Oltre 10 anni'];
$durate = ['m24' => '24 mesi', 'm36' => '36 mesi', 'm48' => '48 mesi', 'm60' => '60 mesi', 'm84' => '84 mesi', 'm120' => '120 mesi'];
$durateMutuo = ['m120' => '120 mesi', 'm180' => '180 mesi', 'm240' => '240 mesi', 'm300' => '300 mesi', 'm360' => '360 mesi'];
$consumo = fn (string $to) => ['personale' => $to, 'quinto' => $to, 'finalizzato' => $to];

$choice = fn (string $label, string $prompt, array $options, string|array $next, array $extra = []) => array_merge(
    ['type' => 'choice', 'label' => $label, 'prompt' => $prompt, 'options' => $options, 'next' => $next], $extra
);
$text = fn (string $label, string $prompt, array $rules, string|array $next, array $extra = []) => array_merge(
    ['type' => 'text', 'label' => $label, 'prompt' => $prompt, 'rules' => $rules, 'next' => $next], $extra
);
$file = fn (string $kind, string $prompt, string $next, array $extra = []) => array_merge(
    ['type' => 'file', 'kind' => $kind, 'prompt' => $prompt, 'next' => $next, 'save' => false], $extra
);
$summary = fn (array $extra = []) => array_merge([
    'type' => 'summary', 'prompt' => 'Confermi i dati inseriti?', 'save' => false,
    'options' => ['conferma' => 'Conferma', 'modifica' => 'Ricomincia', 'annulla' => 'Annulla'],
], $extra);

return [

    // Controlli sulle risposte: nome => classe (App\Services\Checks\NodeCheck). Si agganciano alle domande dal pannello.
    'checks' => [
        'codice_fiscale' => CodiceFiscaleCheck::class,
        'iban' => IbanCheck::class,
        'maggiorenne' => MaggiorenneCheck::class,
        // Controlli sui documenti caricati (non istantanei: girano dopo la risposta al webhook).
        'tipo_documento' => TipoDocumentoCheck::class,
        'dati_coerenti' => DatiCoerentiCheck::class,
        'estrai_dati' => EstraiDatiCheck::class,
        'informativa_firmata' => InformativaFirmataCheck::class,
    ],

    // Controlli sui documenti predefiniti per tipo di lettura (campo ai_kind del catalogo), se il passo non ne ha di suoi.
    'document_checks' => [
        'identita' => ['tipo_documento', 'dati_coerenti', 'estrai_dati'],
        'codice_fiscale' => ['tipo_documento', 'dati_coerenti', 'estrai_dati'],
        'reddito' => ['tipo_documento', 'dati_coerenti'],
        'informativa' => ['informativa_firmata'],
    ],

    'menu' => [
        'body' => 'Ciao! Benvenuto nel servizio agenti. Cosa vuoi fare?',
        // Se il numero è di un produttore riconosciuto ({nome} = il suo nome).
        'body_named' => 'Ciao {nome}! Benvenuto nel servizio agenti. Cosa vuoi fare?',
        'options' => [
            'menu_richiedi' => 'Richiedi Finanziamento',
            'menu_perfeziona' => 'Perfeziona Finanziamento',
            'menu_stato' => 'Stato Pratiche',
        ],

        // Voci di prova: le vede solo chi ha il numero associato a un utente del pannello, e solo se il percorso
        // ha una copia di prova. percorso => [id della voce, titolo (max 24 caratteri)].
        'test' => [
            'richiesta' => ['test_richiedi', 'Prova: Richiedi'],
            'perfezionamento' => ['test_perfeziona', 'Prova: Perfeziona'],
            'documenti' => ['test_stato', 'Prova: Stato Pratiche'],
        ],
    ],

    // Profilo WhatsApp (php artisan whatsapp:setup-profile): comandi con la "/" e messaggi per rompere il ghiaccio.
    // I messaggi (max 4, 80 caratteri) coincidono con i titoli del menu, così il bot li riconosce come scelte.
    'profile' => [
        'enable_welcome_message' => true,
        'prompts' => ['Richiedi Finanziamento', 'Perfeziona Finanziamento', 'Stato Pratiche', 'Aiuto'],
        'commands' => [
            'menu' => 'Mostra le tre opzioni: richiedi, perfeziona, stato pratiche',
            'help' => 'Elenco dei comandi disponibili',
            'indietro' => 'Torna alla domanda precedente',
            'salta' => 'Salta la domanda (dove è consentito)',
            'avanti' => 'Prosegui senza aspettare i controlli sui documenti',
            'annulla' => 'Annulla l\'operazione in corso',
        ],
    ],

    // CRM del committente: codice HTTP restituito dalla simulazione (CRM_SIMULATED_STATUS=500 per provare l'errore).
    'crm' => ['simulated_status' => (int) env('CRM_SIMULATED_STATUS', 200)],

    // Casella dell'istruttoria se non è indicata nella scheda Azienda.
    'mail' => ['to' => env('FINANZIAMENTO_MAIL_TO')],

    'flows' => [

        // Fase 1: nessun dato identificativo, solo profilo a fasce.
        'richiesta' => [
            'start' => 'prodotto',
            'restart' => 'prodotto',
            'nodes' => [
                'prodotto' => $choice('Prodotto', 'Che tipo di finanziamento vuoi richiedere?', [
                    'personale' => 'Prestito personale', 'quinto' => 'Cessione del quinto', 'finalizzato' => 'Finalizzato (beni)',
                    'mutuo' => 'Mutuo', 'leasing' => 'Leasing', 'aziendale' => 'Finanziamento aziendale',
                ], [
                    'personale' => 'importo', 'quinto' => 'importo', 'finalizzato' => 'importo',
                    'mutuo' => 'mutuo_scopo', 'leasing' => 'leasing_bene', 'aziendale' => 'az_forma',
                ]),

                // Comune al consumo
                'importo' => $choice('Importo', 'Quale importo ti serve?', $importi, 'durata'),
                'durata' => $choice('Durata', 'Su quale durata?', $durate, $consumo('lavoro') + ['leasing' => 'leasing_anticipo', 'aziendale' => 'az_finalita'], ['next_by' => 'prodotto']),

                // Credito al consumo
                'lavoro' => $choice('Situazione lavorativa', 'Qual è la situazione lavorativa del cliente?', [
                    'dip_priv' => 'Dipendente privato', 'dip_pub' => 'Dipendente pubblico', 'pensionato' => 'Pensionato',
                    'autonomo' => 'Autonomo', 'altro' => 'Altro',
                ], ['dip_priv' => 'contratto', 'dip_pub' => 'contratto', 'pensionato' => 'ente_pensione', 'autonomo' => 'anni_attivita', '*' => 'impegni']),
                'contratto' => $choice('Contratto', 'Che tipo di contratto ha?', ['indet' => 'Tempo indeterminato', 'det' => 'Tempo determinato'], 'anzianita'),
                'anzianita' => $choice('Anzianità lavorativa', 'Da quanto lavora presso l\'attuale datore?', $anzianita, 'reddito'),
                'reddito' => $choice('Reddito netto mensile', 'Qual è il reddito netto mensile?', $redditi, ['quinto' => 'dimensione_azienda', '*' => 'impegni'], ['next_by' => 'prodotto']),
                'dimensione_azienda' => $choice('Dimensione azienda', 'Quanti dipendenti ha l\'azienda?', ['oltre15' => 'Oltre 15 dipendenti', 'fino15' => 'Fino a 15 dipendenti'], 'impegni'),
                'ente_pensione' => $choice('Ente pensionistico', 'Da quale ente riceve la pensione?', ['inps' => 'INPS', 'exinpdap' => 'Ex INPDAP', 'altro' => 'Altro ente'], 'pensione_netta'),
                'pensione_netta' => $choice('Pensione netta mensile', 'Qual è la pensione netta mensile?', $redditi, 'impegni'),
                'anni_attivita' => $choice('Anni di attività', 'Da quanti anni svolge l\'attività?', $anzianita, 'reddito_autonomo'),
                'reddito_autonomo' => $choice('Reddito', 'Qual è il reddito dell\'ultima dichiarazione (mensile netto)?', $redditi, 'impegni'),
                'impegni' => $choice('Finanziamenti in corso', 'Ci sono finanziamenti in corso?', $yn, ['si' => 'rata', 'no' => 'crif']),
                'rata' => $choice('Rata mensile', 'A quanto ammonta la rata mensile totale?', ['rata_200' => 'Fino a 200 €', 'rata_400' => '200 - 400 €', 'rata_oltre' => 'Oltre 400 €'], 'crif'),
                'crif' => $choice('Segnalazioni CRIF', 'Ci sono segnalazioni in CRIF o protesti?', ['no' => 'No', 'si' => 'Sì', 'nonso' => 'Non so'], ['quinto' => 'quote_cedute', 'finalizzato' => 'bene', '*' => 'riepilogo'], ['next_by' => 'prodotto']),
                'quote_cedute' => $choice('Quote già cedute', 'Ci sono quote dello stipendio già cedute?', $yn, 'riepilogo'),
                'bene' => $choice('Bene', 'Quale bene si vuole acquistare?', ['auto_nuova' => 'Auto nuova', 'auto_usata' => 'Auto usata', 'moto' => 'Moto', 'altro' => 'Altro bene'], 'prezzo_bene'),
                'prezzo_bene' => $choice('Prezzo del bene', 'Qual è il prezzo del bene?', $importi, 'anticipo'),
                'anticipo' => $choice('Anticipo', 'È previsto un anticipo?', $yn, 'riepilogo'),

                // Mutuo
                'mutuo_scopo' => $choice('Scopo', 'Qual è lo scopo del mutuo?', [
                    'prima' => 'Acquisto prima casa', 'seconda' => 'Acquisto seconda casa', 'surroga' => 'Surroga', 'liquidita' => 'Liquidità',
                ], 'mutuo_valore'),
                'mutuo_valore' => $choice('Valore immobile', 'Qual è il valore dell\'immobile?', $grandi, 'mutuo_ltv'),
                'mutuo_ltv' => $choice('Quota da finanziare', 'Quale quota del valore vuoi finanziare?', ['ltv_50' => 'Fino al 50%', 'ltv_80' => '50% - 80%', 'ltv_oltre' => 'Oltre l\'80%'], 'durata_mutuo'),
                'durata_mutuo' => $choice('Durata', 'Su quale durata?', $durateMutuo, 'mutuo_reddito'),
                'mutuo_reddito' => $choice('Reddito familiare', 'Qual è il reddito netto mensile familiare?', [
                    'fam_2000' => 'Fino a 2.000 €', 'fam_3500' => '2.000 - 3.500 €', 'fam_5000' => '3.500 - 5.000 €', 'fam_oltre' => 'Oltre 5.000 €',
                ], 'mutuo_intestatari'),
                'mutuo_intestatari' => $choice('Intestatari', 'Quanti saranno gli intestatari?', ['int_1' => '1 intestatario', 'int_2' => '2 intestatari', 'int_3' => '3 o più'], 'mutuo_tasso'),
                'mutuo_tasso' => $choice('Tasso', 'Che tipo di tasso preferisce?', ['fisso' => 'Tasso fisso', 'variabile' => 'Tasso variabile', 'nonso' => 'Non so'], 'riepilogo'),

                // Leasing
                'leasing_bene' => $choice('Bene', 'Che tipo di bene è in leasing?', ['auto' => 'Auto/veicoli', 'strumentale' => 'Bene strumentale', 'immobiliare' => 'Immobiliare'], 'leasing_valore'),
                'leasing_valore' => $choice('Valore del bene', 'Qual è il valore del bene?', $grandi, 'durata'),
                'leasing_anticipo' => $choice('Anticipo/maxicanone', 'È previsto un anticipo o maxicanone?', $yn, 'leasing_riscatto'),
                'leasing_riscatto' => $choice('Riscatto finale', 'È previsto il riscatto finale?', $yn, 'az_forma'),

                // Aziende (anche per il leasing): nessun dato identificativo
                'az_forma' => $choice('Forma giuridica', 'Qual è la forma giuridica?', [
                    'ditta' => 'Ditta individuale', 'snc_sas' => 'Snc / Sas', 'srl' => 'Srl', 'spa' => 'Spa', 'professionista' => 'Libero professionista',
                ], 'az_anzianita'),
                'az_anzianita' => $choice('Anzianità attività', 'Da quanti anni è attiva?', $anzianita, 'az_fatturato'),
                'az_fatturato' => $choice('Fatturato', 'Qual è il fatturato dell\'ultimo anno?', [
                    'fat_100' => 'Fino a 100.000 €', 'fat_500' => '100.000 - 500.000 €', 'fat_2m' => '500.000 - 2 mln €', 'fat_oltre' => 'Oltre 2 mln €',
                ], ['aziendale' => 'az_importo', 'leasing' => 'riepilogo'], ['next_by' => 'prodotto']),
                'az_importo' => $choice('Importo', 'Quale importo serve?', $grandi, 'durata'),
                'az_finalita' => $choice('Finalità', 'Qual è la finalità?', [
                    'liquidita' => 'Liquidità', 'investimenti' => 'Investimenti', 'macchinari' => 'Acquisto macchinari', 'altro' => 'Altro',
                ], 'az_garanzie'),
                'az_garanzie' => $choice('Garanzie', 'Quali garanzie sono disponibili?', [
                    'fondo_pmi' => 'Fondo Garanzia PMI', 'ipoteca' => 'Ipoteca', 'garante' => 'Garante personale', 'nessuna' => 'Nessuna',
                ], 'riepilogo'),

                'riepilogo' => $summary(),
            ],
        ],

        // Fase 2: dati personali solo dopo l'informativa firmata.
        'perfezionamento' => [
            'start' => 'codice',
            'restart' => 'codice_fiscale',
            'labels' => ['data_nascita' => 'Data di nascita', 'sesso' => 'Sesso'],
            'nodes' => [
                'codice' => ['type' => 'code', 'prompt' => 'Inserisci il codice della pratica (es. FIN-2026-0001):', 'save' => false, 'next' => 'conferma_pratica'],
                'conferma_pratica' => $choice('Conferma', 'È la pratica giusta?', $yn, ['si' => 'riepilogo_documenti', 'no' => 'codice'], ['save' => false, 'prompt_summary' => true]),
                // Messaggio senza risposta: riassume i documenti del finanziamento e dà il link all'informativa da far firmare.
                // Segnaposto: {codice}, {prodotto}, {documenti} (dal catalogo del prodotto), {informativa_url}.
                'riepilogo_documenti' => [
                    'type' => 'message', 'save' => false, 'next' => 'informativa',
                    'prompt' => "📄 *Documenti per perfezionare la pratica {codice}* ({prodotto})\n\n{documenti}\n\n🔒 *Informativa privacy*\nPrima dei dati personali serve l'informativa firmata dal cliente. Scaricala da questo link, stampala e falla firmare:\n{informativa_url}",
                ],
                'informativa' => $file('informativa', 'Per procedere invia l\'informativa privacy firmata dal cliente (foto o PDF).', 'doc_identita', ['skip_if' => 'privacy_received', 'analyze' => true, 'ack' => true]),

                // I documenti si chiedono subito dopo l'informativa: l'AI li legge dopo (non subito) e propone i dati da confermare.
                'doc_identita' => $file('documento_identita', 'Invia il documento d\'identità del cliente (foto o PDF).', 'doc_cf', ['skip_if' => 'received:documento_identita', 'analyze' => true, 'ack' => true]),
                'doc_cf' => $file('codice_fiscale', 'Invia il codice fiscale del cliente (foto o PDF).', 'doc_reddito', ['skip_if' => 'received:codice_fiscale', 'analyze' => true, 'ack' => true]),
                'doc_reddito' => $file('reddito', 'Invia il documento di reddito (busta paga, CUD, cedolino pensione, dichiarazione o bilancio).', 'attesa_documenti', ['skippable' => true, 'skip_if' => 'received:reddito', 'analyze' => true, 'ack' => true]),
                // Si aspetta la fine dei controlli sui documenti; il dialogo riparte da solo (o con «avanti»).
                'attesa_documenti' => ['type' => 'wait', 'prompt' => "⏳ Sto controllando i documenti: ti scrivo appena ho finito.\nSe non vuoi aspettare scrivi «avanti».", 'save' => false, 'next' => 'rivedi_dati'],
                // Dati letti dai documenti: confermati dall'agente valgono come risposte e fanno saltare le domande corrispondenti.
                'rivedi_dati' => ['type' => 'review', 'prompt' => 'I dati letti dai documenti sono corretti?', 'save' => false, 'next' => 'codice_fiscale', 'skip_if' => 'no_proposals', 'options' => ['conferma' => 'Sì, confermo', 'correggi' => 'No, li inserisco io']],

                // Dal codice fiscale si ricavano data, sesso e luogo di nascita; poi si verifica che cognome e nome siano coerenti.
                'codice_fiscale' => $text('Codice fiscale', 'Codice fiscale del cliente (da qui ricavo data e luogo di nascita):', ['required', 'regex:/^[A-Z]{6}[0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{3}[A-Z]$/'], 'cognome', [
                    'upper' => true, 'strip_spaces' => true, 'skip_if' => 'filled:codice_fiscale',
                    'error' => 'Codice fiscale non valido (16 caratteri), riprova.',
                    // Ricava data, sesso e luogo di nascita; poi controlla l'età sulla data ricavata.
                    'checks' => ['codice_fiscale', ['name' => 'maggiorenne', 'campo' => 'data_nascita', 'anni' => 18, 'messaggio' => 'Dal codice fiscale il cliente risulta minorenne: controlla il codice.']],
                ]),
                'cognome' => $text('Cognome', 'Cognome del cliente:', ['required', 'string', 'max:60'], 'nome', ['show_derived' => true, 'skip_if' => 'filled:cognome']),
                'nome' => $text('Nome', 'Nome del cliente:', ['required', 'string', 'max:60'], 'verifica_cf', ['skip_if' => 'filled:nome']),
                'verifica_cf' => [
                    'type' => 'check', 'check' => 'cf_names', 'prompt' => 'Cognome e nome coerenti con il codice fiscale?', 'save' => false,
                    'next' => ['ok' => 'luogo_nascita', 'mismatch' => 'conferma_cf'], 'outcomes' => ['ok' => 'Coerenti', 'mismatch' => 'Non coerenti'],
                ],
                'conferma_cf' => $choice('Conferma codice fiscale', 'Confermi il codice fiscale inserito?', ['cf_ok' => 'Confermo il codice', 'cf_no' => 'Lo reinserisco'], ['cf_ok' => 'luogo_nascita', 'cf_no' => 'codice_fiscale'], ['save' => false, 'show_difformita' => true, 'reask' => ['cf_no' => 'codice_fiscale']]),
                'luogo_nascita' => $text('Luogo di nascita', 'Luogo di nascita (non ricavabile dal codice fiscale):', ['required', 'string', 'max:80'], 'residenza', ['skip_if' => 'filled:luogo_nascita']),
                'residenza' => $text('Residenza', 'Indirizzo di residenza (via, numero, CAP, città):', ['required', 'string', 'max:160'], 'stato_civile'),
                'stato_civile' => $choice('Stato civile', 'Stato civile:', ['celibe' => 'Celibe/Nubile', 'coniugato' => 'Coniugato/a', 'separato' => 'Separato/a', 'vedovo' => 'Vedovo/a'], 'documento_tipo'),
                'documento_tipo' => $choice('Documento', 'Tipo di documento d\'identità:', ['ci' => 'Carta d\'identità', 'patente' => 'Patente', 'passaporto' => 'Passaporto'], 'documento_numero'),
                'documento_numero' => $text('Numero documento', 'Numero del documento:', ['required', 'string', 'max:30'], 'documento_scadenza', ['skip_if' => 'filled:documento_numero']),
                'documento_scadenza' => $text('Scadenza documento', 'Scadenza del documento (gg/mm/aaaa):', ['required', 'date_format:d/m/Y'], 'telefono', ['skip_if' => 'filled:documento_scadenza', 'error' => 'Data non valida: usa il formato gg/mm/aaaa.']),
                'telefono' => $text('Telefono', 'Telefono del cliente:', ['required', 'regex:/^\+?\d{8,15}$/'], 'email', ['strip_spaces' => true, 'error' => 'Numero non valido, riprova.']),
                'email' => $text('Email', 'Email del cliente:', ['required', 'email'], 'iban', ['error' => 'Email non valida, riprova.']),
                'iban' => $text('IBAN', 'IBAN per l\'erogazione:', ['required', 'regex:/^IT\d{2}[A-Z0-9]{23}$/'], ['aziendale' => 'ragione_sociale', 'leasing' => 'ragione_sociale', '*' => 'datore_lavoro'], ['upper' => true, 'strip_spaces' => true, 'checks' => ['iban'], 'next_by' => 'prodotto', 'error' => 'IBAN non valido (formato o checksum errati), riprova.']),

                'datore_lavoro' => $text('Datore di lavoro / ente', 'Datore di lavoro, ente pensionistico o attività svolta:', ['required', 'string', 'max:120'], 'data_assunzione'),
                'data_assunzione' => $text('Inizio rapporto', 'Data di inizio rapporto o attività (gg/mm/aaaa):', ['required', 'date_format:d/m/Y'], 'riepilogo_p', ['error' => 'Data non valida: usa il formato gg/mm/aaaa.']),
                'ragione_sociale' => $text('Ragione sociale', 'Ragione sociale:', ['required', 'string', 'max:120'], 'partita_iva'),
                'partita_iva' => $text('Partita IVA', 'Partita IVA (11 cifre):', ['required', 'regex:/^\d{11}$/'], 'riepilogo_p', ['strip_spaces' => true, 'error' => 'La partita IVA deve avere 11 cifre.']),

                'riepilogo_p' => $summary(['prompt' => 'Invio la pratica in istruttoria al mediatore creditizio?', 'show_difformita' => true, 'options' => [
                    'conferma' => 'Invia in istruttoria', 'modifica' => 'Ricomincia', 'annulla' => 'Annulla',
                ], 'docs' => [
                    'documento_identita' => 'Documento d\'identità', 'codice_fiscale' => 'Codice fiscale', 'reddito' => 'Documento di reddito',
                ]]),
            ],
        ],
        // Da "Stato Pratiche": si sceglie la pratica e si caricano (anche in giorni diversi) i documenti mancanti o da correggere.
        'documenti' => [
            'start' => 'pratica',
            'restart' => 'pratica',
            'nodes' => [
                'pratica' => [
                    'type' => 'choice', 'label' => 'Pratica', 'prompt' => 'Scegli la pratica:', 'options_from' => 'agent_loans',
                    'prompt_with' => 'loans_list', 'binds_loan' => true, 'save' => false, 'next' => 'dettaglio',
                ],
                'dettaglio' => $choice('Azione', 'Cosa vuoi fare?', ['carica' => 'Carica documenti', 'altra' => 'Altra pratica'], ['carica' => 'tipo', 'altra' => 'pratica'], [
                    'prompt_with' => 'doc_checklist', 'guards' => ['carica' => 'privacy_received'], 'save' => false,
                ]),
                // I documenti proposti sono quelli della pratica (catalogo del tipo di finanziamento) non ancora OK.
                'tipo' => $choice('Documento', 'Quale documento vuoi inviare?', ['fine' => 'Ho finito'], ['fine' => 'dettaglio', '*' => 'upload'], ['options_from' => 'loan_documents']),
                'upload' => [
                    'type' => 'file', 'kind_from' => 'tipo', 'prompt' => 'Invia la foto o il PDF del documento, un file alla volta. Un file nuovo sostituisce quello precedente nella verifica.',
                    'next' => 'tipo', 'save' => false, 'ack' => true, 'analyze' => true,
                ],
            ],
        ],
    ],
];
