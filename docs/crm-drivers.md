# CRM dell'istruttoria: driver

La pratica perfezionata arriva al CRM dell'azienda. Ogni azienda usa **un solo** CRM; il CRM si sceglie in *Aziende → CRM dell'istruttoria*
(`companies.crm_driver`, configurazione cifrata in `companies.crm_config`).

| Valore | Significato |
|---|---|
| vuoto | come prima: Mediafacile se c'è `url_istruttoria`, altrimenti email |
| `email` | nessun CRM: dati e allegati vanno per email all'istruttoria |
| `mediafacile` | servizio di caricamento lead Mediafacile (GET, risposta XML) |
| `generic` | qualsiasi CRM con API REST, configurato da pannello (sotto) |

`CRM_DRIVER=simulated` (sviluppo, test) fa rispondere la simulazione per tutte le aziende.

## Come funziona

`CrmGateway` (quello usato dalla conversazione) è `CompanyCrmGateway`: legge l'azienda della pratica, chiede a `CrmRegistry` il driver
e gli passa la pratica. `submit()` restituisce un codice HTTP: **200 = accettata**, altro = errore e l'agente può riprovare.
Nei log finiscono solo il tipo di errore e il codice, mai dati personali.

## Driver generico (`generic`)

Chiavi di `crm_config`: `url` (https; http solo in sviluppo), `method` (POST), `format` (`json` | `form`), `auth` (`none` | `bearer` |
`header` | `basic`), `token`, `header_name`, `username`, `password`, `timeout`, `body_template`, `success_codes`, `ok_path` + `ok_value`,
`id_path`.

`body_template` è un JSON con segnaposto `{{percorso}}`; un segnaposto da solo conserva il tipo (numero, null), dentro un testo diventa testo.
Vuoto = si invia l'intero formato unico. `id_path` dice dove leggere l'identificativo della pratica nella risposta (finisce in `loan_requests.crm_lead_id`).

### Formato unico (`SubmissionPayload`)

`riferimento`, `prodotto`, `prodotto_etichetta`, `risposte{…}`, `pratica{importo_richiesto, importo_fascia, durata_mesi}`,
`cliente{cognome, nome, codice_fiscale, data_nascita (ISO), luogo_nascita, telefono, email, residenza{indirizzo, citta, provincia}}`,
`agente{nome, sigla, partita_iva, whatsapp}`, `consenso{privacy_ricevuta_il, privacy_verificata_il, contatto_diretto}`,
`documenti[{codice, nome, obbligo, stato, allegati[{tipo, mime, ricevuto_il}]}]`. Nessun contenuto di file.

## Aggiungere un CRM con codice

Una classe che implementa `CrmGateway` (costruttore con `Company $company` se serve la configurazione), registrata in `CrmRegistry`
(`$registry->register('nome', Classe::class, 'Etichetta')`): compare subito nel pannello.

## Funzioni oltre l'invio (capacità opzionali)

Un driver dichiara cosa sa fare implementando le interfacce di `App\Services\Crm\Capabilities`; `CrmRegistry::supports()` risponde.
Nessun driver le implementa ancora: sono il punto di arrivo per unicoloan.

| Interfaccia | Funzione |
|---|---|
| `SendsDocuments` | consegna i documenti della pratica |
| `ProvidesTemplates` | elenco e scarico dei template dei moduli |
| `FillsForms` | modulo PDF compilato con i dati della pratica, da stampare |
| `RequestsSignature` | firma elettronica con OTP e stato della firma |

Sono tutte funzioni che unico-core e unicoloan hanno già (`PdfFormFiller`, `SignatureRequestService`, tipi documento): il driver `unicoloan`
le espone tramite l'API in ingresso di unicoloan, ancora da scrivere.
