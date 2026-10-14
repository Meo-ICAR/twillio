# Percorsi per company, GBD Energy (Edison) e switch company

Data: 2026-10-14 · Stato: da approvare

## Obiettivo
Far servire a unicoagent più company con percorsi propri. Prima nuova company: **GBD Energy**, che stipula contratti Edison tramite installatori di fotovoltaico che fanno cross selling dal cliente. Gli installatori scrivono al bot WhatsApp; dal numero si ricava il `Fornitore` e quindi la company (`Company::forWhatsApp()`, già esistente: nessun numero dedicato).

Flusso GBD, come per il finanziamento: **simulazione** (consumi attuali, preventivatore che consiglia la migliore offerta) e **perfezionamento** (sottoscrizione del contratto con firma OTP del cliente, di cui si chiedono email e cellulare).

## Fuori ambito
Ruoli e permessi (un solo utente, superadmin). Integrazione diretta con Edison (solo mail a GBD). Rinominare `LoanRequest`.

## Design

### 1. Percorsi per company
- `flows.code` non è più unico globale: unico su `(company_id, code)`.
- `FlowRepository` carica solo i percorsi della company di chi scrive (`Company::forWhatsApp($wa)`); la memoria interna è per company e variante prova/produzione. Senza `company_id` valido vale il comportamento attuale (company più vecchia).
- Ogni company usa gli stessi codici (`richiesta`, `perfezionamento`, `documenti`) con alberi propri. Il motore resta com'è: cambia quale albero legge.
- Il menu resta in `config/finanziamento.php` come predefinito. Campo JSON `companies.menu` (opzionale) per titoli e voci della company; vuoto = predefinito.

### 2. Prodotti generici
- `products` esteso con `code`, `description`, `is_active`, `params` (JSON). Il pivot `company_products` dice cosa offre ogni company.
- Per Edison `params` = prezzo €/kWh o €/Smc, quota fissa mensile, durata, bonus. Per il finanziamento resta vuoto.
- Il prodotto scelto è il `product_type` della pratica (colonna `loan_requests.product`, già presente).
- Tipo company `ENERGY` e tipo prodotto `ENERGY` (aggiunti alle costanti dei tipi).

### 3. Simulazione: `EnergyQuoteEstimator`
Implementa `LoanEstimator`, scelto per company (come oggi per Mediafacile/random). Input: consumo annuo (kWh e/o Smc), potenza impegnata, spesa attuale. Calcola il costo annuo di ogni prodotto ENERGY attivo della company e restituisce il più conveniente con il risparmio stimato. I prezzi del seeder sono **dimostrativi**.

### 4. Perfezionamento e firma (unico-core)
- Il percorso `perfezionamento` di GBD raccoglie dati anagrafici e di fornitura (POD/PDR, indirizzo, fornitore attuale), privacy, email e cellulare del cliente.
- Si compila il PDF del contratto e si chiama `Unico\Core\Signature\SignatureRequestService::send()` con il cliente come firmatario. L'OTP via SMS lo gestisce il provider (Yousign; `fake` in sviluppo): il codice lo vede solo il cliente, il bot non lo gestisce.
- All'evento `SignatureCompleted` la pratica diventa perfezionata (`perfected_at`) e parte la mail a GBD con contratto firmato e dati. Il bot comunica all'installatore se la firma è in attesa, completata o scaduta.
- Prerequisito: PDF del contratto con campi compilabili e riquadri di firma (`document_types` → `pdf_modules`, `pdftk`). Finché manca, modello segnaposto e `SIGNATURE_DRIVER=fake`. Per Yousign: `YOUSIGN_API_KEY` sandbox. Il valore legale (firma elettronica semplice) va confermato con GBD.

### 5. Switch company nel pannello (senza ruoli)
- Selettore nella barra del pannello Filament: elenco delle company più «Tutte»; la scelta sta in sessione.
- Nessuno scope globale e nessun ruolo. Le tabelle delle risorse con `company_id` (Percorsi, Fornitori, Pratiche, Conversazioni, Prodotti, Controlli) hanno un filtro `company_id` **preimpostato** sulla company scelta; «Tutte» lo toglie. L'utente può sempre modificarlo.
- I form di creazione precompilano `company_id` con la company scelta.

### 6. Dati iniziali (seeder)
Company GBD Energy (tipo ENERGY), i suoi percorsi `richiesta` e `perfezionamento` (e `documenti`), menu, prodotti Edison di esempio (luce e gas), `Fornitore` «Installatore 1» tel 3927968199 legato a GBD. I percorsi esistenti restano alla company 1.

## Test
- `FlowRepository` isola i percorsi per company e usa il predefinito senza company.
- Un numero di GBD vede il menu e i percorsi di GBD; un numero della company 1 vede i suoi.
- `EnergyQuoteEstimator` sceglie il prodotto più conveniente e gestisce zero prodotti.
- La firma: invio con provider `fake`, completamento, scadenza.
- Il filtro dello switcher preimposta `company_id`; «Tutte» non filtra.
- I test esistenti restano verdi (company 1 invariata).

## Rischi
- Le funzioni che oggi usano `Company::current()` e `config('finanziamento.flows')` come ripiego vanno riviste una per una, altrimenti un percorso di una company può finire all'altra.
- Il cambio dell'indice unico richiede una migrazione sulla tabella con dati: si fa in modo reversibile.
