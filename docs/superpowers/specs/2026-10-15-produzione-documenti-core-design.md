# Produzione dei documenti con unico-core (fase 1: produzione e firma)

Data: 2026-10-15 · Stato: da approvare · Segue: `2026-10-14-company-percorsi-energy-design.md`

## Obiettivo
unicoagent produce i documenti di una pratica (informativa, contratto) con gli strumenti di unico-core invece di delegare al CRM:
compila i moduli PDF (`pdf_modules`), li registra come `Document` del core e li fa firmare con OTP (`SignatureRequestService`, provider Yousign o `fake`).
Quali moduli produrre lo dicono i `tasks` del core (documenti per fase). Prima company: GBD Energy (contratti Edison, tipo pratica «Utenza»).

## Fuori ambito (fase 2)
Migrare catalogo (`FinanziamentoDocument`), slot (`PraticaDocument`), caricamenti (`Attachment`), pipeline di lettura e `SharePointUploader` di unicoagent sul core. Anagrafica clienti nel registro `clients` del core (scelta B: i documenti sono agganciati alla pratica locale e nessun dato del cliente entra nel registro condiviso). Messaggi WhatsApp fuori finestra (servono modelli approvati da Meta). Il PDF del contratto Edison, finché non c'è.

## Fatti verificati (DB `unico_core_test`, connessione `core`)
- Esistono `document_types` (195), `document_requirements` (430, con `task_id`), `tasks` (123), `pdf_modules` (13, con 851 campi), `pdf_module_fields`, `documents`, `signature_requests`.
- Un task dice in quale stato di quale tipo di pratica vale: `trigger_field=tipo_prodotto`, `trigger_value=<tipo>`, `trigger_subfield=stato_pratica`, `trigger_subvalue=<stato>`; ne esistono 88 con `stato_pratica`. `tasks` non ha `company_id`.
- Il tipo «Utenza» c'è già (stati `Inserita`, `ACCETTATO PREVENTIVO`, `PERFEZIONATA`, `SOSPESA`, `RINUNCIA CLIENTE`; task 95 «Utenza · Inserita»). `PERFEZIONARE` esiste solo per il Mutuo. `ALLA FIRMA` non esiste. `pratica_stati_transizioni` è vuota.
- L'informativa privacy 2025 è il tipo 111 con modulo 4: nessun campo da compilare (0/13), solo il riquadro di firma `cliente`.
- `ModulePrinter::render(PdfModule, ResolvedModuleData, flatten)` accetta dati forniti dall'app; le chiavi dei moduli sono `client.*`, `pratica.*`, `agent.*`, `kyc.*`, `document.identity.*`. `pdftk` è installato.
- `YousignSignatureProvider` e `FakeSignatureProvider` sono nel core; il provider si sceglie con `SIGNATURE_DRIVER`; la rotta `POST signature/webhook/{provider}` è già registrata in unicoagent. `unico-core.enforce_morph_map` è `false`.
- `Document` richiede `documentable_type` e `documentable_id`; `spatie/laravel-medialibrary` è installato ma unicoagent non ha `config/media-library.php`.

## Design

### 1. Core (repository `unico-core`)
- Migrazione: `tasks.company_id` nullable, chiave esterna su `companies` (vuoto = valido per tutte le aziende, com'è oggi). Si segue il formato del core (`tools/schema-merge/spec.py` e `comments.py`) e si aggiorna il modello `Task` (fillable, relazione `company`).
- Dati (seeder del core, ripetibile) per la company GBD, tipo `Utenza`:
  - stati di `Utenza`: `INVIO IN ISTRUTTORIA`, `ALLA FIRMA`, `PERFEZIONARE`;
  - task 1 «Utenza · Inserita (GBD)» con i documenti da raccogliere;
  - task 2 «Utenza · ALLA FIRMA (GBD)» con informativa 2025 (tipo 111) e il contratto Edison (tipo e modulo nuovi, aggiunti quando c'è il PDF).
- L'applicazione al DB condiviso `unico_core_test` avviene solo con l'ok esplicito dell'utente, dopo backup.

### 2. unicoagent
- **Configurazione:** `config/media-library.php` con il `Media` del core; alias morph `loan_request` → `App\Models\LoanRequest`; `config/energy.php` (o la scheda Azienda) indica per GBD il tipo del core (`Utenza`) e l'id company del core.
- **Opt-in per company:** `companies.produce_documents` (bool, default false). Senza, il comportamento resta quello di oggi: il finanziamento non cambia.
- **`LoanTaskResolver`:** dato `LoanRequest` + stato, restituisce i `document_requirements` dei task con `is_active`, `company_id` nullo o uguale alla company del core, `tipo_prodotto` = tipo, `stato_pratica` = stato.
- **`LoanModuleData`:** `LoanRequest` → `ResolvedModuleData` con `client.*` (da `personal`: cognome, nome, codice fiscale, data e luogo di nascita, residenza, email, telefono; per un'azienda ragione sociale e partita IVA), `pratica.oggi`, `agent.*`, più le chiavi del fornitore attuale e del punto di fornitura (POD/PDR) per il contratto.
- **`LoanDocumentProducer`:** per ogni requisito con un `PdfModule` attivo compila il PDF (`ModulePrinter::render`, `flatten` solo all'invio), crea il `Document` (company del core, tipo, `documentable` = la pratica, `channel` = `generato`, collection `documents`) e chiama `SignatureRequestService::send()` con `SignerInput` del cliente (per un'azienda il legale rappresentante). Idempotente: un documento già prodotto o con firma aperta non si duplica.
- **Listener** sugli eventi del core `SignatureSent`, `SignatureCompleted`, `SignatureCancelled`: aggiornano `loan_requests.signature_status`, `signed_at` e lo stato.
- **Pannello:** nella pratica si vedono i documenti prodotti, lo stato di firma e l'azione «Reinvia per la firma»; «Stato pratiche» del bot mostra lo stato di firma.
- **Configurazione ambiente:** `SIGNATURE_DRIVER` (`fake` finché non ci sono le chiavi), `YOUSIGN_API_KEY`, `YOUSIGN_WEBHOOK_SECRET`; URL del webhook `https://unicoagent.hassisto.com/signature/webhook/yousign`.

### 3. Stati di GBD
Si separano due momenti (richiesta dell'utente):
1. **Trasmessa in istruttoria** (`INVIO IN ISTRUTTORIA`, locale `invio_istruttoria`): al «Conferma» la pratica è registrata, parte la mail a GBD come oggi, si producono i documenti e partono per la firma. Evento a UnicoBPM **non** ancora.
2. **Completa di moduli firmati** (`PERFEZIONARE`, locale `perfezionare`): quando tutte le firme richieste sono complete. Parte la seconda mail a GBD con i PDF firmati, parte l'evento `pratica.perfezionata` verso UnicoBPM e (se la finestra di 24 ore è aperta) l'avviso all'installatore.
3. `PERFEZIONATA` resta allo stato finale del back-office.

`ALLA FIRMA` non è uno stato della pratica in unicoagent: è il nome della fase/task dei documenti da firmare e il valore dello stato di firma (`signature_status` = `in_attesa` | `firmata` | `scaduta` | `rifiutata` | `errore`) mostrato nel pannello e nel bot.
Per le company con `produce_documents = false` gli stati restano `perfezionata` come oggi.

### 4. Errori
- Produzione o invio in firma non riuscita dopo il «Conferma»: la pratica resta `invio_istruttoria` con `signature_status = errore`, si registra il motivo (senza dati personali nel log), e il pannello permette di riprovare. Il perfezionamento non si annulla.
- Firmatario senza email o cellulare validi: errore esplicito con il campo mancante; nessuna richiesta parte.
- Firma scaduta o rifiutata: stato di firma aggiornato, la pratica resta `invio_istruttoria`; si può reinviare.
- Webhook con provider diverso da quello attivo: ignorato dal core.

## Test
- Selezione requisiti: solo task attivi della company o senza company, del tipo e dello stato giusto.
- `LoanModuleData`: privato e azienda, chiavi presenti, campi mancanti.
- `LoanDocumentProducer` con `SIGNATURE_DRIVER=fake`: crea i `Document`, invia la firma, è idempotente, gestisce firmatario non valido.
- Listener: firma completata → `perfezionare`, `signed_at`, mail con PDF firmati, evento BPM una sola volta; scaduta/rifiutata/errore.
- Company senza `produce_documents`: nessun cambiamento (suite esistente verde).
- Core: migrazione `tasks.company_id` e seeder GBD ripetibile (suite del core).

## Rischi
- Scrittura sul DB condiviso `unico_core_test` (schema e dati): backup e ok esplicito prima di applicare.
- Il contratto Edison e le chiavi Yousign non ci sono ancora: si parte con la sola informativa e provider `fake`.
- Il webhook richiede un URL pubblico raggiungibile da Yousign.
- WhatsApp: oltre le 24 ore dall'ultimo messaggio dell'installatore l'avviso automatico non parte; restano mail e «Stato pratiche».

## Punti da confermare
- Stato 1 e 2 come sopra, con `ALLA FIRMA` come stato di firma e non come stato della pratica.
- Evento `pratica.perfezionata` a UnicoBPM alla firma completata solo per le company con `produce_documents`.
