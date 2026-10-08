# Preventivatore Mediafacile: intervallo dell'importo erogato

Data: 2026-10-08 · Stato: bozza da approvare · Fonte: "Specifiche Servizio WS Offerte 3.8" (sommarie: il tracciato definitivo arriverà).

## Obiettivo
Sostituire `RandomLoanEstimator` con una stima basata sul servizio di simulazione Mediafacile. Al produttore si mostra, come oggi, *"Importo ottenibile: da X € a Y €"*, dove X e Y sono l'**importo erogato** minimo e massimo. Di ogni offerta si usa solo `Importo_erogato`; `Importo_provvigione` e tutto il resto dell'output sono ignorati.

## Come si ottiene l'intervallo
La richiesta è anonima e contiene solo fasce, quindi si fanno **due simulazioni**:

| | Scenario migliore (massimo) | Scenario peggiore (minimo) |
|---|---|---|
| Età | estremo giovane della fascia | estremo anziano della fascia |
| Anzianità lavorativa | estremo alto della fascia | estremo basso della fascia |
| Reddito | estremo alto della fascia | estremo basso della fascia |

- `Importo_erogato` massimo = il più alto tra le offerte valide dello scenario migliore. Minimo = il più basso tra quelle dello scenario peggiore.
- Offerta valida: `Errore=2` (corretta). Le offerte con `Errore=1` si scartano.
- Se tutte sono scartate o la chiamata fallisce, non si mostra nessun importo: la richiesta parte per email all'istruttoria, come per le company senza preventivatore, con l'avviso già esistente.

## Dati inviati
| Campo servizio | Come lo ricaviamo |
|---|---|
| `Passkey` | colonna della company (nuova `preventivatore_passkey`); l'URL è `url_preventivatore`, già presente |
| `Tipo_contratto` | prodotto `quinto` → Cessione; `personale` → Prestito. Il Finalizzato non si simula: resta il comportamento attuale |
| `Tipo_rapporto` | tabella di mappatura dalle risposte (lavoro, contratto, ente pensione, dimensione azienda) |
| `Data_nascita` | **1° gennaio** dell'anno ottenuto da *anno corrente − età*; per la fascia 40-50 anni: 01/01/1986 (migliore) e 01/01/1976 (peggiore) |
| `Data_assunzione` | oggi meno gli anni di anzianità dello scenario, al 1° gennaio |
| `Data_decorrenza` (solo Cessione/Delega) | 2 mesi dopo la data della richiesta |
| `Durata` | la durata scelta dal produttore (se non è tra quelle ammesse dal servizio, la più vicina ammessa) |
| `Importo_rata` (Cessione/Delega) | `reddito_mensile ÷ 5` (le fasce di reddito sono mensili) |
| `Importo_richiesto`, `Reddito_richiedenti` (Prestito) | estremo della fascia importo e della fascia reddito dello scenario |
| `Sesso` | risposta alla nuova domanda; se non risponde, **M** (predefinito configurabile) |
| `Rinnovo` | sempre `NO`; i campi del rinnovo non si inviano |
| `Provvigione` | non gestita; non inviata (o 0 se il servizio la esige) |

Formati: date `MM-GG-ANNO`, decimali con la virgola, come da PDF.

## Domande aggiunte alla richiesta
Per `personale` e `quinto`, dopo la durata (il Finalizzato le salta):
- **Fascia d'età** del cliente.
- **Sesso** (M / F); se si salta, vale M.

## Fasce chiuse per Cessione e Prestito
Per questi due prodotti le fasce non sono più aperte ("Fino a…", "Oltre…"): ognuna ha un estremo basso e uno alto realistici, e le etichette mostrate al produttore cambiano di conseguenza. Valori proposti, da confermare:

| Fascia | Opzioni (estremi in euro / anni) |
|---|---|
| Età | 20-30 · 30-40 · 40-50 · 50-60 · 60-75 |
| Anzianità | 0-1 · 1-3 · 3-10 · 10-20 · 20-30 · 30-40 anni |
| Reddito netto mensile | 1.000-1.500 · 1.500-2.000 · 2.000-3.000 · 3.000-5.000 |
| Importo (solo Prestito) | 1.000-5.000 · 5.000-10.000 · 10.000-20.000 · 20.000-35.000 · 35.000-50.000 |

Le altre linee di prodotto (mutuo, leasing, aziendale, finalizzato) conservano le loro fasce.

## Tabelle
Tutte configurabili e compilate da un seeder, così quando arriva il tracciato cambiano i dati e non il codice.

- `quote_contract_types`: valore inviato, prodotto interno, elenco dei campi richiesti.
- `quote_employment_types`: gli 11 valori di `Tipo_rapporto` e i contratti con cui sono ammessi (i pensionati solo con Cessione e Prestito).
- `quote_durations`: durate ammesse per contratto (Cessione/Delega 24-120; Prestito anche 12).
- `quote_employment_map`: risposte del produttore → `Tipo_rapporto`.
- `quote_band_bounds`: per ogni codice di fascia (età, anzianità, reddito, importo) estremo basso e alto. I valori iniziali sono quelli della tabella sopra, da tarare.
- `quote_simulations`: per ogni richiesta e scenario, i dati inviati, la risposta grezza e gli importi erogati minimo e massimo, per audit.

## Componenti
- `LoanEstimator`: interfaccia invariata (`{min, max}` in euro).
- `MediafacileLoanEstimator`: costruisce i due scenari, li invia, unisce i risultati.
- `MediafacileClient`: richiesta HTTP POST e lettura della risposta XML. **È l'unico punto che cambia con il tracciato definitivo.** Nomi degli elementi XML e codifica della richiesta oggi sono ipotesi.
- Il driver si sceglie con `finanziamento.quote.driver` (`random` o `mediafacile`); di base `random`, così nulla cambia finché non c'è l'endpoint.

## Test
Chiamate simulate con `Http::fake` e una risposta XML di esempio: scenari e date (inclusa la data di nascita al 1° gennaio), calcolo della rata, scarto delle offerte `Errore=1`, ripiego sull'email in caso di errore o timeout, mappatura di `Tipo_rapporto`.

## Fuori ambito
Provvigione, rinnovo, mutuo, finalizzato, leasing e aziendale; invio della pratica al CRM (resta `CrmGateway`).

## Punti aperti con la software house
Struttura XML della risposta e codifica della richiesta (form o XML) · significato di `Data_decorrenza` · `Importo_richiesto` descritto come "rata" · "Mutuo" citato ma non previsto · sezione 3.3 citata per la durata della Cessione.
