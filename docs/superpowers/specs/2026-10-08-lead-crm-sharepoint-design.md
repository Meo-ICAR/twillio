# Invio lead al CRM e archiviazione documenti su SharePoint

Data: 2026-10-08 · Stato: bozza da approvare · Fonte: "Specifiche caricamento Lead GENERICO 1.6" (Mediafacile AI).

## Obiettivo
A pratica perfezionata (a valle di `completePerfezionamento`):
1. caricare il lead sul CRM con il servizio Mediafacile;
2. archiviare i documenti della pratica su SharePoint, in background.

**Nessun documento va al CRM**: il parametro `file` non si invia.

## Lead sul CRM
`MediafacileLeadGateway` implementa l'interfaccia `CrmGateway` esistente (`submit(LoanRequest, array $personal): int`). Si sceglie con `CRM_DRIVER` = `simulated` (di base, `SimulatedCrmGateway`) o `mediafacile`. Si usa solo se la company ha l'URL dell'istruttoria (`url_istruttoria`); senza, resta l'invio per email.

**Chiamata:** GET sull'URL dell'istruttoria con i parametri nella query, risposta XML.

| Parametro | Valore |
|---|---|
| `Passkey` | nuova colonna cifrata `companies.istruttoria_passkey` (scheda Azienda), distinta da quella del preventivatore |
| `cognome`, `nome` | dati del perfezionamento |
| `data_nascita` | dal codice fiscale, formato `MM-GG-ANNO` |
| `tipologia` | dalla mappatura risposte → tipo di impiego (colonna `lead_tipologia` in `quote_employment_map`) |
| `importo_richiesto` | estremo alto della fascia di importo (`quote_band_bounds`), decimali con la virgola |
| `residenza_citta` | ultimo segmento dell'indirizzo di residenza (separato da virgola) |
| `residenza_provincia` | sigla chiesta con la nuova domanda `residenza_provincia` |
| `cellulare`, `email` | quelli del cliente |
| `fonte` | `WhatsApp - {sigla del produttore}` (formato in `finanziamento.lead.fonte`) |
| `annotazioni` | codice pratica, prodotto, durata, fascia di importo (etichetta), produttore. Mai IBAN, documento o codice fiscale |
| `file` | **non inviato** |

**Valori di `tipologia`:** Statale, Pubblico, Privato, Privato altra forma, Privato small business, Medico, Pensionato INPS, Pensionato altri enti, Postale, Ferroviere, Parapubblico. Dalla mappatura del preventivatore: Privato SPA → Privato; Pensionato INPDAP → Pensionato altri enti; gli altri uguali.

**Esito:** `Stato` che comincia con `OK` → il gateway restituisce 200 e salva `IDUU` su `loan_requests.crm_lead_id`. `KO - …`, XML non valido, errore HTTP o timeout → restituisce un codice diverso da 200: il produttore vede «Invio pratica fallito, riprovare o contattare Istruttoria» e può riprovare, come oggi. Nel log solo il tipo di errore, mai i dati.

**Rischio noto:** il servizio risponde «KO - Caricato ma errori rilevati»: il lead risulta comunque caricato lato CRM, quindi un nuovo tentativo può creare un doppione. Si accetta questo comportamento (decisione del titolare); la software house va avvisata.

## Domanda aggiunta al perfezionamento
`residenza_provincia`: «Provincia di residenza (sigla, es. MI):», testo, regola `required` e `^[A-Za-z]{2}$`, maiuscolo, dopo `residenza`. Migrazione per gli alberi esistenti, che non tocca modifiche manuali.

## Archiviazione su SharePoint
- `SharePointUploader::upload(string $folder, string $filename, string $contents): void` (interfaccia).
- `LoggingSharePointUploader`: implementazione finta; scrive nel log cartella e nome file, **senza contenuto né dati personali**. Verrà sostituita con le routine reali.
- `ArchiveLoanDocuments` (job in coda, 3 tentativi): dopo il perfezionamento riuscito, con CRM o con email, per ogni allegato della pratica non rifiutato (informativa firmata compresa) chiama `upload(codice pratica, nome file, contenuto)`. A successo scrive `loan_requests.documents_archived_at`. Un errore non blocca né annulla il perfezionamento.
- Per le pratiche di prova (`is_test`) non si archivia.

## Dati e configurazione
- Colonne nuove: `companies.istruttoria_passkey` (cifrata), `loan_requests.crm_lead_id`, `loan_requests.documents_archived_at`, `quote_employment_map.lead_tipologia`.
- Config: `finanziamento.crm.driver` (`CRM_DRIVER`), `finanziamento.lead.fonte`.

## Test
GET simulata (parametri nella query, `OK`/`KO`, XML non valido, timeout, nessun `file`); mappatura di `tipologia` e della città; la nuova domanda e la sua migrazione; il job (cicla gli allegati, salta i rifiutati e le prove, scrive `documents_archived_at`, non blocca in caso di errore); il perfezionamento che chiama il lead e avvia l'archiviazione.

## Fuori ambito
Invio del PDF o di altri documenti al CRM; routine SharePoint reali (le fornisce il titolare); correzione dei duplicati del CRM; invio dei lead per i percorsi senza `url_istruttoria` (restano per email).

## Punti aperti con la software house
Tracciato XML della risposta (nomi degli elementi `Stato` e `IDUU`) · `file` in GET con base64 non praticabile (serve POST?) · comportamento sui duplicati · elenco dei valori di `fonte` accettati.
