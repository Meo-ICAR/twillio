# GBD Energy: nuovo preventivo e perfezionamento con lettura AI dei documenti

Data: 2026-10-16 · Stato: da approvare · Segue: `2026-10-14-company-percorsi-energy-design.md`, `2026-10-15-produzione-documenti-core-design.md`

## Obiettivo
L'installatore perfeziona su WhatsApp un contratto Edison: carica i documenti del cliente (bolletta, identità, visura, estratto conto), l'AI li legge, il bot chiede solo ciò che manca e il cliente firma con OTP tramite unico-core.

## Già fatto (non committato al momento di questa spec)
Flow `richiesta` sul modello dell'IVR (profilo, fornitura luce/gas/luce_gas, residenza o tensione, spesa mensile), catalogo offerte Edison (anche media tensione) e `EnergyQuoteEstimator` che ricava il consumo dalla spesa. `skip_if: product_not:` accetta `luce_gas`.

## Fuori ambito
Contratto Edison reale (si simula, vedi sotto), valore legale della firma da confermare con GBD, integrazione diretta con Edison, rotte web: tutto resta su WhatsApp.

## Fatti verificati (DB `unico_core_test`, codice di `unico-core`)
- `document_types` ha già: Visura camerale (34, `is_ai_check`), Estratto conto bancario (42), Carta d'identità (38, `ai_kind=identita`), Contratto utenza (140, senza modulo), Informativa privacy 2025 (111, modulo 4). **Mancano**: contratto di fornitura Edison, modulo SDD, dichiarazione sul titolo di possesso dell'immobile, bolletta.
- `ai_kind` è valorizzato solo per `identita`, `codice_fiscale`, `informativa`; i `pdf_modules` hanno `signature_slots` (ruolo `client`) e i campi in `pdf_module_fields`.
- unico-core ha già `OpenApiCompanyService::fetchCompany(P.IVA)` (Openapi.it `IT-advanced`: ragione sociale, PEC, SDI, ATECO, sede, soci). Il credit score è solo simulato (`openapi.creditscore_live`). **Catasto non esiste.**
- unicoagent ha già: nodi `file` con `analyze` (`AnthropicDocumentReader`), nodo `review` che fa confermare i dati letti, `skip_if: filled:<campo>`, `skippable`, attesa dei controlli (`wait`), produzione e firma (`LoanDocumentProducer`, `LoanSignatureFinalizer`).

## Design

### 1. Core (repository `unico-core`)
**Tipi di documento nuovi** (seeder ripetibile `database/seeders/data/document_types.json` + inserimento nel DB condiviso, dopo backup `mysqldump unico_core_test`; l'utente ha autorizzato l'aggiunta):
| Nome | Tipo | Note |
|---|---|---|
| Contratto di fornitura Edison | `template_fillable`, `is_signed`, pratica | simulato |
| Modulo SDD (domiciliazione bancaria) | `template_fillable`, `is_signed` | simulato |
| Dichiarazione titolo di possesso immobile | `template_fillable`, `is_signed` | simulato (D.L. 47/2014) |
| Bolletta luce/gas | `incoming`, `ai_kind=bolletta` | caricato dal cliente |

Si imposta `ai_kind` anche su Visura camerale (`visura`) ed Estratto conto (`estratto_conto`), così le letture partono dal tipo. Effetto su chi già li usa: il controllo `tipo_documento` verifica che l'AI riconosca lo stesso tipo (già nelle regole attuali, `is_ai_check=1`); lo verifico con i test del core.

**Moduli PDF simulati** (`pdf_modules`, tre file in `module/`): generati con campi compilabili (`Button`/testo) e riquadro firma `client`, nello stesso formato di quelli esistenti; `pdf_module_fields` collega i campi alle chiavi `client.*`, `fornitura.*`, `pratica.*`. Sono segnaposto riconoscibili («FAC-SIMILE – contratto Edison»): si sostituiscono con i PDF reali senza toccare il codice (stessa riga `pdf_modules`, nuovo file).

**Task `Utenza · ALLA FIRMA (GBD)`**: requisiti = informativa 2025 + contratto Edison + SDD (se pagamento SDD) + dichiarazione immobile. Task `Utenza · Inserita (GBD)`: bolletta, identità, visura (solo azienda), estratto conto.

**API esterne con Openapi.it** (`config/unico-core.php` → `openapi`):
- Registro Imprese: si riusa `OpenApiCompanyService::fetchCompany`, esposto a unicoagent con un metodo che non scrive sul cliente del core (nessun dato nel registro condiviso, come nella spec precedente).
- Catasto: nuovo `Clients\OpenApiCadastreService` (dati catastali da codice fiscale e indirizzo), stesso schema del credit score: chiave `catasto_url`, interruttore `catasto_live` (spento = risposta simulata, nessuna chiamata), timeout, token in `OPENAPI_TOKEN`. L'endpoint e il formato di risposta si ricavano dalla definizione OpenAPI del prodotto Catasto sul portale Openapi.it prima di scriverlo; il test usa un file di esempio in `tests/Fixtures`, come per `IT-advanced`.
- Il Catasto serve solo ai privati, parte in background dopo codice fiscale + indirizzo (job in coda) e **non blocca il flusso**: se non risponde il perfezionamento continua.

### 2. unicoagent: albero `perfezionamento` di GBD (`config/energy.php`)
Ordine: pratica → **carica documenti** → controlli AI → conferma dei dati letti → **solo le domande che mancano** → dati integrativi → riepilogo → firma.
1. **Pratica**: codice e conferma, come oggi. `tipo_cliente` non si chiede più: si legge da `answers.profilo` del preventivo (privato/azienda).
2. **Documenti** (nodi `file`, ciascuno con il tasto **«Salta»**; se un file è già arrivato `skip_if: received:`): bolletta; documento d'identità; visura camerale (solo azienda); foto estratto conto o IBAN. L'AI legge:
   - bolletta → POD/PDR, indirizzo fornitura, potenza impegnata, fornitore attuale;
   - identità → nome, cognome, codice fiscale, nascita, residenza, numero, scadenza;
   - visura → ragione sociale, P.IVA, ATECO, sede, legale rappresentante;
   - estratto conto → IBAN, intestatario.
   Nuovi schemi in `AnthropicDocumentReader` per `bolletta`, `visura`, `estratto_conto`; controlli in `config/finanziamento.php` (`document_checks`) come per identità.
3. **Attesa e conferma**: `attesa_documenti` poi `rivedi_dati` («Sì, confermo» / «No, li inserisco io»). I dati confermati riempiono le risposte e fanno saltare le domande relative.
4. **Dopo i caricamenti il bot chiede ciò che manca**, con le domande a mano già presenti (POD/PDR con la maschera `IT…`, indirizzo, potenza richiesta con default 3 kW per i privati, anagrafica, IBAN con checksum). Per l'azienda, se manca la visura, basta la P.IVA: il Registro Imprese compila ragione sociale, PEC, SDI e sede; ciò che non restituisce (es. legale rappresentante) si chiede.
5. **Pagamento**: «Addebito SDD» (default), «Bollettino/carta». Con SDD: se l'intestatario letto non coincide col titolare, la pratica è segnata «terzo intestatario»; a mano si chiede «Il conto è intestato al richiedente?».
6. **Dati integrativi sempre richiesti**: titolo di possesso (Proprietà / Affitto / Comodato); destinazione d'uso (solo privati; proposta da `answers.residenza` del preventivo, confermabile); email; cellulare (serve per l'OTP). Controlli `email` e `mobile` come oggi.
7. **Riepilogo pre-firma** con 🟢 per i dati letti dall'AI e 🟡 per quelli digitati; si usa il campo di provenienza già salvato per i dati proposti dai documenti (da verificare nel codice in fase di piano). Conferma/Ricomincia/Annulla come oggi.

### 3. Firma
Alla conferma, `LoanDocumentProducer` compila il plico dai requisiti del task `ALLA FIRMA (GBD)` e `SignatureRequestService::send()` manda al cliente la richiesta di firma: l'OTP via SMS e la firma elettronica avanzata li gestisce il provider (Yousign, oppure `fake` in sviluppo); il cliente firma sul link, non in chat. Per un'azienda firma il legale rappresentante. Il bot comunica lo stato (in attesa, firmata, scaduta, errore) e, a firma completata, parte la mail a GBD con i PDF firmati, come da spec precedente.

### 4. Errori
- File illeggibile o di tipo sbagliato: si segnala e si ripropone «Carica di nuovo / Salta / Inserisco a mano».
- Registro Imprese o Catasto non disponibili: si prosegue con le domande a mano; il Catasto mancante non blocca.
- Firmatario senza email o cellulare validi: errore esplicito, nessuna richiesta parte (invariato).

## Test
- Core: i nuovi `document_types` e `pdf_modules` si creano e si ripetono senza duplicati; il file del contratto simulato si compila (`ModulePrinter`) e ha il riquadro firma; `OpenApiCadastreService` con fixture, simulato e `live` spento.
- unicoagent: per ogni sezione, ramo «carica» (con `DocumentReader` finto) e ramo «Salta» con le domande a mano; ramo azienda con P.IVA; SDD con intestatario diverso; riepilogo 🟢/🟡; plico prodotto e firma `fake`; catasto in background che non blocca; suite esistente verde (i 2 test già rossi prima delle modifiche, `BpmEventsTest` e `ArchiviazioneDopoPerfezionamentoTest`, non sono parte di questo lavoro).

## Rischi
- Scrittura sul DB condiviso `unico_core_test`: backup e inserimenti ripetibili; impostare `ai_kind` sui tipi già esistenti tocca anche le altre company del core.
- Il formato di risposta del Catasto di Openapi.it non è verificato: si scrive dopo aver letto la definizione OpenAPI, e resta simulato finché non c'è il token.
- I PDF Edison sono fac-simile: nessun valore contrattuale; i campi vanno riallineati ai moduli reali.
- Costi della lettura AI e delle API esterne a pagamento (Registro Imprese, Catasto): da concordare con GBD.
