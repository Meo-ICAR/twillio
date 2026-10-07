# Dialogo WhatsApp: Richiedi / Perfeziona Finanziamento — Design

Data: 2026-10-07 · Stato: bozza da approvare

## 1. Obiettivo

Un mediatore creditizio (prevalentemente credito al consumo, più altri prodotti) usa gli
**agenti** via WhatsApp per aprire e completare pratiche di finanziamento. Il bot guida
l'agente con un albero di domande e salva tutto nel database Laravel.

Successo: l'agente apre una pratica anonima in chat e ottiene un codice; in un secondo
momento la perfeziona con i dati del cliente, solo dopo aver inviato l'informativa privacy
firmata. Nulla esce dal database (nessuna notifica, nessun CRM: fase successiva).

## 2. Menu iniziale

Lista interattiva (i pulsanti hanno max 20 caratteri, i titoli non entrano):

| Voce (max 24 car.)       | Id                    |
|--------------------------|-----------------------|
| Richiedi Finanziamento   | `menu_richiedi`       |
| Perfeziona Finanziamento | `menu_perfeziona`     |
| Stato Pratiche           | `menu_stato`          |

Resta anche la scelta testuale `1`, `2`, `3` e le parole chiave già presenti.

## 3. Principi

1. **Fase 1 anonima.** Nessun dato che identifichi cliente o azienda: niente nome, codice
   fiscale, telefono, email, ragione sociale, P.IVA, indirizzo. Valori a fasce quando
   possibile. Niente note libere né allegati.
2. **Fase 2 dopo l'informativa.** I dati personali si chiedono solo dopo aver ricevuto
   l'informativa privacy firmata (file). Basta riceverla: il bot accetta il file e sblocca la
   raccolta. Non può verificare la firma; ricezione e data restano tracciate (`privacy_received_at`
   e allegato), l'eventuale controllo è esterno al sistema.
3. **Albero come dati.** Le domande stanno in `config/finanziamento.php`; un motore generico
   le esegue. Aggiungere un prodotto o una domanda non richiede codice.
4. **Stato in database.** La conversazione sopravvive tra un messaggio e l'altro e oltre le
   24 ore.

## 4. Modello dati

**conversations**
- `id`, `wa_number` (agente), `flow` (`richiesta`|`perfezionamento`), `node` (nodo corrente),
  `loan_request_id` (nullable), `status` (`attiva`|`completata`|`annullata`),
  timestamp.
- Una sola conversazione `attiva` per `wa_number`.

**loan_requests**
- `id`, `code` (univoco, `FIN-AAAA-NNNN`), `agent_wa_number`, `product`,
  `status` (`richiesta` → `in_attesa_informativa` → `informativa_ricevuta` → `perfezionata`),
  `answers` (JSON, solo dati anonimi), `personal` (JSON **cifrato**, cast `encrypted:array`),
  `privacy_received_at`, `perfected_at`, timestamp.

**attachments**
- `id`, `loan_request_id`, `kind` (`informativa`, `documento_identita`, `codice_fiscale`,
  `busta_paga`, ...), `path`, `mime`, `wa_media_id`, `received_at`.
- File sul disco **privato** (`storage/app/private`), mai pubblico.

Controllo di proprietà: un agente accede solo alle pratiche con il proprio `wa_number`.

## 5. Componenti

| Unità | Responsabilità | Dipende da |
|---|---|---|
| `WhatsAppController` | riceve il webhook, estrae il messaggio, delega al motore | `ConversationEngine` |
| `ConversationEngine` | stato, validazione, avanzamento nei nodi, comandi | config, modelli, `SensitiveDataGuard` |
| `WhatsAppClient` | invio testo/lista/pulsanti, download media da Meta | `Http`, `config/services.php` |
| `SensitiveDataGuard` | rifiuta risposte che somigliano a CF, telefono, email, P.IVA (solo fase 1) | — |
| `LoanRequestCode` | genera il codice progressivo annuale in transazione | `loan_requests` |
| `config/finanziamento.php` | albero: nodi, tipi, validazioni, salti | — |

Il motore restituisce "messaggi da inviare" e non chiama Meta: così si testa senza rete.

## 6. Nodo dell'albero (schema)

```php
'importo' => [
    'type'     => 'choice',            // text | number | choice | yesno | file
    'prompt'   => "Quale importo?",
    'options'  => ['fino_10k' => 'Fino a 10.000 €', ...],   // lista o pulsanti
    'validate' => 'required',          // regole Laravel per text/number
    'save_as'  => 'importo',
    'next'     => 'durata',            // stringa, oppure mappa valore => nodo
],
```

`next` è dichiarativo (niente closure, quindi compatibile con `config:cache`). Rami condizionali:
`'next' => ['dipendente' => 'contratto', 'pensionato' => 'ente_pensione', '*' => 'impegni']`.
Se le opzioni sono ≤3 si usano pulsanti, altrimenti lista (max 10).

## 7. Fase 1 — Richiedi Finanziamento (anonima)

Comune: prodotto (personale · cessione del quinto · finalizzato · mutuo · leasing ·
aziendale), importo (fasce), durata (24/36/48/60/84/120 mesi).

- **Consumo**: situazione lavorativa; dipendente → contratto, anzianità (fasce), reddito netto
  (fasce), dimensione azienda (cessione); pensionato → ente, pensione netta (fasce);
  autonomo → anni di attività, reddito (fasce); finanziamenti in corso (+ rata in fasce);
  CRIF (sì/no/non so); cessione → quote già cedute; finalizzato → bene, prezzo (fasce), anticipo.
- **Mutuo**: scopo; valore immobile (fasce) e % sul valore (avviso oltre l'80%); reddito
  familiare (fasce); intestatari; tasso preferito.
- **Leasing**: bene; valore (fasce); anticipo; riscatto.
- **Aziendale**: forma giuridica; anzianità e fatturato (fasce); finalità; garanzie.
- **Chiusura**: riepilogo anonimo → Conferma / Modifica / Annulla. Alla conferma: pratica in
  stato `richiesta`, risposta con il codice.

## 8. Fase 2 — Perfeziona Finanziamento

1. Chiede il codice. Errori distinti: non trovato · di un altro agente · già perfezionata.
2. Mostra il riepilogo anonimo e chiede conferma.
3. **Blocco informativa:** chiede il file dell'informativa firmata (immagine o PDF). Alla
   ricezione salva l'allegato, imposta `privacy_received_at`, stato `informativa_ricevuta`.
   Prima di questo punto nessun dato personale viene chiesto.
4. Dati personali (secondo prodotto): anagrafica, nascita, residenza e domicilio, stato civile,
   documento d'identità (tipo, numero, scadenza), email, IBAN, datore di lavoro e data di
   assunzione; per le aziende ragione sociale e P.IVA. Salvati cifrati in `personal`.
5. Documenti obbligatori per prodotto, con checklist "mancano: …".
6. Riepilogo finale e conferma → stato `perfezionata`, `perfected_at`.

Il testo e il contenuto dell'informativa e dei consensi li fornisce il mediatore; il sistema
non produce documenti legali.

## 9. Comandi, errori, ripresa

- `indietro` (torna al nodo precedente), `menu`, `annulla`: validi in ogni nodo.
- Risposta non valida: stessa domanda con un messaggio d'aiuto, lo stato non avanza.
- Lo stato avanza solo dopo l'invio riuscito della domanda successiva; ogni errore verso Meta
  è registrato nel log e il webhook risponde comunque 200 per evitare i retry a catena.
- Conversazione ferma oltre 24 ore: alla ripresa, "continua" o "ricomincia".

## 10. Sicurezza e privacy

- Dati personali cifrati a riposo (cast) e file sul disco privato.
- Fase 1: `SensitiveDataGuard` blocca testo con pattern di CF, telefono, email, P.IVA.
- I log non riportano i campi in `personal` né i media.

## 11. Test

Test automatici (`php artisan test`):
- motore: un test di conversazione completa per ogni ramo, `indietro`/`annulla`, risposta non
  valida, deduplica, ripresa dopo 24 ore;
- `SensitiveDataGuard`: casi positivi e negativi;
- fase 2: codice inesistente, di altro agente, già perfezionata; blocco prima dell'informativa;
- `WhatsAppClient` con `Http::fake()`.

## 12. Fuori ambito (per ora)

Verifica della firma `X-Hub-Signature-256` del webhook, deduplica dei messaggi reinviati da
Meta, notifiche al backoffice, integrazione con gestionale/CRM, pannello web, validazione
automatica della firma, calcolo di preventivi reali, comando "Stato Pratiche" oltre
all'elenco di base delle pratiche dell'agente.
