<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manuale utente</title>
    <meta name="description" content="Manuale d'uso di UnicoAgent: come lavora l'agente su WhatsApp e come si gestiscono pratiche, documenti e configurazione dal pannello.">
    <link rel="icon" href="/unicoagent_logo.png">
    <style>
        :root { --bg:#f6f8f7; --fg:#14211c; --muted:#55655e; --card:#fff; --line:#dbe4df; --brand:#0f7b5f; --soft:#e3f4ee; }
        @media (prefers-color-scheme: dark) { :root { --bg:#0e1512; --fg:#e8f0ec; --muted:#9bada5; --card:#16201b; --line:#26352e; --brand:#2bb48e; --soft:#14302a; } }
        * { box-sizing: border-box; }
        body { margin:0; background:var(--bg); color:var(--fg); font:16.5px/1.65 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        a { color:var(--brand); }
        .wrap { max-width:900px; margin:0 auto; padding:0 20px 60px; }
        header.top { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:16px 0; flex-wrap:wrap; }
        header.top img { height:40px; }
        h1 { font-size:clamp(1.7rem,4vw,2.3rem); line-height:1.2; margin:10px 0 6px; }
        h2 { font-size:1.35rem; margin:42px 0 8px; padding-top:8px; border-top:1px solid var(--line); }
        h3 { font-size:1.08rem; margin:26px 0 6px; }
        .meta { color:var(--muted); margin:0 0 20px; }
        nav.toc { background:var(--card); border:1px solid var(--line); border-radius:14px; padding:14px 22px; margin:18px 0; }
        nav.toc ol { margin:6px 0; padding-left:20px; columns:2; column-gap:30px; }
        @media (max-width:640px) { nav.toc ol { columns:1; } }
        .box { background:var(--soft); border:1px solid var(--line); border-radius:12px; padding:12px 18px; margin:14px 0; }
        .warn { background:#fff4e0; border-color:#e8c27a; color:#3d2600; }
        @media (prefers-color-scheme: dark) { .warn { background:#3a2c10; color:#f5ddb0; border-color:#6b5320; } }
        table { width:100%; border-collapse:collapse; margin:12px 0; font-size:.96rem; }
        th, td { text-align:left; padding:8px 10px; border-bottom:1px solid var(--line); vertical-align:top; }
        th { color:var(--muted); font-weight:600; }
        code, kbd { background:var(--soft); border-radius:6px; padding:1px 6px; font-size:.92em; }
        .chat { background:var(--card); border:1px solid var(--line); border-left:4px solid var(--brand); border-radius:10px; padding:10px 16px; margin:10px 0; font-size:.96rem; }
        ol.steps li, ul li { margin:4px 0; }
        @media print { nav.toc, header.top a { display:none; } body { background:#fff; color:#000; } }
    </style>
</head>
<body>
<div class="wrap">
    <header class="top">
        <img src="/unicoagent_banner.png" alt="UnicoAgent">
        <span><a href="/admin">Pannello</a> · <a href="/">Home</a></span>
    </header>

    <h1>Manuale utente</h1>
    <p class="meta">Per gli agenti che usano WhatsApp e per chi lavora nel pannello di amministrazione.</p>

    <nav class="toc">
        <strong>Indice</strong>
        <ol>
            <li><a href="#cose">Cos'è e come funziona</a></li>
            <li><a href="#agente">Per l'agente: WhatsApp</a></li>
            <li><a href="#richiedi">Richiedi Finanziamento</a></li>
            <li><a href="#perfeziona">Perfeziona Finanziamento</a></li>
            <li><a href="#stato">Stato Pratiche</a></li>
            <li><a href="#pannello">Il pannello</a></li>
            <li><a href="#pratiche">Pratiche e documenti</a></li>
            <li><a href="#config">Settings: percorsi, documenti, controlli</a></li>
            <li><a href="#anagrafiche">Anagrafiche</a></li>
            <li><a href="#invii">Invii a CRM e email</a></li>
            <li><a href="#dati">Dati personali e conservazione</a></li>
            <li><a href="#problemi">Se qualcosa non funziona</a></li>
        </ol>
    </nav>

    <h2 id="cose">1. Cos'è e come funziona</h2>
    <p>UnicoAgent è l'assistente WhatsApp con cui gli agenti aprono e completano le pratiche di finanziamento. Il lavoro avviene in due fasi:</p>
    <ol class="steps">
        <li><strong>Richiesta</strong>: anonima, solo dati di profilo (importo, durata, situazione lavorativa…). Non si scrivono nome, codice fiscale, telefono o email del cliente.</li>
        <li><strong>Perfezionamento</strong>: dopo l'informativa privacy firmata dal cliente si inviano i documenti e si completano i dati personali. La pratica va poi in istruttoria.</li>
    </ol>
    <p>La decisione sul finanziamento è sempre di un istruttore: il servizio raccoglie, controlla e ordina le informazioni. L'intelligenza artificiale serve solo a leggere i documenti e propone dei dati che l'agente conferma.</p>

    <h2 id="agente">2. Per l'agente: WhatsApp</h2>
    <p>Scrivi al numero del servizio. Al primo messaggio compare il menu (se il tuo numero è registrato tra i produttori, ti saluta per nome):</p>
    <table>
        <tr><th>Voce</th><th>A cosa serve</th></tr>
        <tr><td>Richiedi Finanziamento</td><td>Apre una nuova pratica anonima e ti dà un codice.</td></tr>
        <tr><td>Modifica Preventivo</td><td>Elenca gli ultimi 5 preventivi non perfezionati: scegli quale modificare.</td></tr>
        <tr><td>Perfeziona Finanziamento</td><td>Completa una pratica con i dati del cliente e i documenti: scegli tra gli ultimi 5 preventivi o le pratiche a cui mancano ancora documenti.</td></tr>
        <tr><td>Stato Pratiche</td><td>Elenco delle tue pratiche; da qui carichi documenti mancanti o richiesti.</td></tr>
    </table>
    <h3>Comandi utili, in qualsiasi momento</h3>
    <table>
        <tr><td><kbd>menu</kbd></td><td>Torna al menu (la conversazione in corso viene chiusa).</td></tr>
        <tr><td><kbd>annulla</kbd></td><td>Annulla l'operazione.</td></tr>
        <tr><td><kbd>indietro</kbd></td><td>Torna alla domanda precedente.</td></tr>
        <tr><td><kbd>salta</kbd></td><td>Salta una domanda, quando il bot lo permette (lo scrive in fondo alla domanda).</td></tr>
        <tr><td><kbd>avanti</kbd></td><td>Mentre il bot controlla i documenti: prosegui senza aspettare.</td></tr>
        <tr><td><kbd>help</kbd> o <kbd>aiuto</kbd></td><td>Elenco dei comandi; poi il bot ripropone la domanda in corso.</td></tr>
    </table>
    <p>I comandi si possono scrivere anche con la barra (<kbd>/menu</kbd>, <kbd>/help</kbd>…): sono quelli che WhatsApp mostra quando scrivi «/» nella chat. Nel profilo dell'azienda compaiono anche i messaggi per rompere il ghiaccio (Richiedi Finanziamento, Perfeziona Finanziamento, Stato Pratiche, Aiuto), che aprono direttamente la voce scelta.</p>
    <p>Puoi rispondere toccando i pulsanti o le liste, oppure scrivendo il testo (o il numero) dell'opzione. Se lasci una conversazione ferma per più di 24 ore, il bot ti chiede se continuare o ricominciare.</p>

    <h2 id="richiedi">3. Richiedi Finanziamento</h2>
    <ol class="steps">
        <li>Scegli il tipo di finanziamento (prestito personale, cessione del quinto, finalizzato, mutuo, leasing, aziendale).</li>
        <li>Rispondi alle domande: cambiano in base al prodotto. Sono sempre a scelta, mai dati identificativi.</li>
        <li>Controlla il riepilogo e tocca <em>Conferma</em>, <em>Modifica</em> (ricomincia) o <em>Annulla</em>.</li>
    </ol>
    <div class="chat">✅ Richiesta registrata. Codice pratica: <strong>FIN-2026-0001</strong>. Conservalo: ti servirà per perfezionare il finanziamento.</div>
    <p><strong>Modifica del preventivo.</strong> Dal menu scegli <em>Modifica Preventivo</em> e il preventivo dall'elenco, oppure usa il pulsante <em>Modifica preventivo</em> sotto il messaggio di fine richiesta (non compare per i prodotti che non hanno dati modificabili). Il bot crea un nuovo preventivo copiando tutti i dati del precedente e ti chiede solo quelli modificabili (per esempio importo e durata): per ognuno vedi il valore attuale e puoi scegliere un'altra opzione oppure <em>Mantieni attuale</em> (o scrivere <kbd>ok</kbd>). Alla fine confermi il riepilogo: il preventivo di partenza non cambia e il nuovo ha un suo codice.</p>
        <p><strong>Cosa vedi dopo il codice</strong> dipende da chi sei e da come è configurata l'azienda:</p>
    <ul>
        <li><strong>Produttore con preventivatore (CRM) configurato</strong>: l'importo minimo e massimo ottenibile.</li>
        <li><strong>Produttore senza preventivatore</strong>: la richiesta viene inoltrata per email all'istruttoria, che ti ricontatta con l'esito.</li>
        <li><strong>Numero non registrato tra i produttori</strong> (segnalatore occasionale): non vedi gli importi e ti viene chiesto di telefonare al customer care dell'azienda, di cui trovi telefono ed email nel messaggio. Il tuo numero viene registrato come segnalatore occasionale.</li>
    </ul>
    <div class="box warn">Se per sbaglio scrivi dati del cliente in questa fase, il bot li rifiuta e ti ricorda di non inserirli.</div>

    <h2 id="perfeziona">4. Perfeziona Finanziamento</h2>
    <ol class="steps">
        <li><strong>Scelta della pratica</strong>: scegli dall'elenco (gli ultimi 5 preventivi da perfezionare e le pratiche già inviate a cui mancano documenti) oppure scrivi il codice (es. FIN-2026-0001), poi conferma che è la pratica giusta. Se scegli una pratica già perfezionata a cui mancano documenti, vai direttamente al loro caricamento.</li>
        <li><strong>Riepilogo dei documenti</strong>: il bot elenca i documenti necessari per quel finanziamento e ti dà il link dell'informativa privacy da scaricare, stampare e far firmare al cliente.</li>
        <li><strong>Informativa firmata</strong>: invia una foto o un PDF. Deve essere il nostro modulo e deve essere firmato; viene controllato in automatico e ti arriva l'esito. Finché non è a posto, gli altri documenti restano in attesa e non vengono letti.</li>
        <li><strong>Documenti</strong>: invia, uno alla volta, documento d'identità, codice fiscale e documento di reddito (quest'ultimo si può saltare). Un documento già inviato non viene richiesto di nuovo. Per ognuno il bot ti scrive l'esito del controllo dopo qualche istante, senza fermarti.</li>
        <li><strong>Attesa</strong>: se i controlli non sono finiti, il bot dice che sta controllando e riparte da solo. Se non vuoi aspettare scrivi <kbd>avanti</kbd>.</li>
        <li><strong>Conferma dei dati letti</strong>: il bot mostra ciò che ha letto dai documenti (cognome, nome, codice fiscale, numero e scadenza del documento). Scegli <em>Sì, confermo</em>: quelle domande vengono saltate. Con <em>No, li inserisco io</em> compili tutto a mano. Se un dato letto non è valido (per esempio un codice fiscale errato o di un minorenne) il bot te lo dice e lo richiede.</li>
        <li><strong>Domande sui dati</strong>: codice fiscale (da cui ricava data e luogo di nascita), cognome e nome, residenza, stato civile, documento, telefono, email, IBAN, dati del lavoro o dell'azienda. Ogni risposta è controllata: se non è valida la domanda si ripete.</li>
        <li><strong>Riepilogo finale</strong>: vedi dati, stato dei documenti ed eventuali dati difformi da verificare. Tocca <em>Invia in istruttoria</em>.</li>
    </ol>
    <p>Lo stato dei documenti nel riepilogo: ✅ a posto · 📎 ricevuto, in verifica · ⚠️ da correggere · ➖ mancante.</p>
    <div class="chat">✅ Pratica FIN-2026-0001 perfezionata e inviata in istruttoria al mediatore creditizio.</div>
    <div class="box warn"><strong>Invio pratica fallito, riprovare o contattare Istruttoria</strong>: l'invio non è andato a buon fine. La pratica non è cambiata e i dati sono ancora lì: tocca di nuovo <em>Invia in istruttoria</em> dopo qualche minuto; se continua, contatta l'istruttoria. Se alcuni documenti sono ancora in controllo, il bot ti chiede di aspettare l'esito prima di inviare.</div>

    <h2 id="stato">5. Stato Pratiche</h2>
    <ol class="steps">
        <li>Scegli una delle tue pratiche dall'elenco (le ultime 10).</li>
        <li>Vedi i documenti raggruppati in <em>Obbligatori</em>, <em>Facoltativi</em> e <em>Integrazioni richieste</em>, ciascuno con il suo stato e l'ultima nota dell'AI o dell'istruttore.</li>
        <li>Tocca <em>Carica documenti</em>, scegli il documento e invia la foto o il PDF. Puoi inviarne quanti vuoi, anche in giorni diversi; un file nuovo sostituisce il precedente nella verifica.</li>
    </ol>
    <p>Se l'istruttore rifiuta un documento o chiede un'integrazione, ricevi un messaggio WhatsApp con la nota. Per caricare documenti serve prima l'informativa firmata.</p>

    <h2 id="pannello">6. Il pannello</h2>
    <p>Si apre da <a href="/admin">/admin</a> con email e password. Il menu laterale ha: <strong>Pratiche</strong>, <strong>Conversazioni</strong>, il gruppo <strong>Settings</strong> (Percorsi di configurazione, Documenti per il finanziamento, Controlli sulle risposte) e il gruppo <strong>Anagrafiche</strong> (Azienda, Produttori, Utenti).</p>
    <h3>Dashboard</h3>
    <ul>
        <li><strong>Da fare adesso</strong>: documenti rifiutati, documenti non letti dall'AI, informative da verificare, pratiche perfezionate negli ultimi 7 giorni. Cliccando si va alle pratiche.</li>
        <li><strong>Integrazioni</strong>: stato di WhatsApp, analisi AI e pulizia automatica.</li>
        <li><strong>Ultimi 7 giorni</strong>: pratiche nuove per stato, pratiche vicine alla cancellazione, conversazioni attive e ferme.</li>
        <li>Pulsanti <em>Verifica WhatsApp</em> (legge i dati del numero, non manda messaggi) e <em>Verifica AI</em> (richiesta minima ad Anthropic: dice se la chiave è valida e se il credito è esaurito; l'importo residuo non è disponibile via API).</li>
    </ul>

    <h2 id="pratiche">7. Pratiche e documenti</h2>
    <p><strong>Pratiche</strong> elenca tutte le richieste, con filtri per stato, origine (reale o di prova) e prodotto. La scheda mostra codice, agente, stato, quando è stata ricevuta e <em>verificata</em> l'informativa, le risposte della richiesta, i dati personali e gli eventuali dati difformi segnalati dal bot. Sotto trovi tre tabelle:</p>
    <ul>
        <li><strong>Documenti della pratica</strong>: ogni documento atteso con stato (Da ricevere, Ricevuto, OK, Rifiutato, Integrazione richiesta) e annotazioni di AI e operatore. Azioni: <em>Approva</em>, <em>Rifiuta</em> (con nota: l'agente la riceve su WhatsApp), <em>Chiedi integrazione</em> e, dall'alto, <em>Richiedi un documento integrativo</em> (dal catalogo o libero).</li>
        <li><strong>Dati letti dai documenti</strong>: ciò che l'AI ha letto, con stato Proposto / Confermato / Rifiutato e il documento di provenienza. Sola lettura.</li>
        <li><strong>Allegati</strong>: i file ricevuti con l'esito del controllo (Verificato, Difforme, Non leggibile, Non analizzato, In attesa informativa) e le differenze trovate.</li>
    </ul>
    <div class="box"><strong>Informativa</strong>: finché non risulta verificata, i documenti della pratica non vengono inviati all'AI. Se l'AI non riesce a leggerla o la rifiuta, apri il documento <em>Informativa firmata</em> e usa <em>Approva</em>: da quel momento i documenti in attesa vengono analizzati.</div>
    <p>Dall'elenco puoi selezionare più pratiche e usare <em>Invia per email (forza)</em> per rimandare all'istruttoria dati e allegati anche se già inviati. La colonna <em>Inviata per email</em> mostra l'ultimo invio.</p>
    <p><strong>Conversazioni</strong> mostra le conversazioni WhatsApp (numero, produttore associato, percorso, domanda corrente, stato). I dati in corso non sono visibili: sono cancellati quando la conversazione si chiude.</p>

    <h2 id="config">8. Settings: percorsi, documenti, controlli</h2>
    <h3>Percorsi di configurazione</h3>
    <p>Le domande del bot sono nelle tabelle e si modificano senza programmare. Per ogni percorso (Richiesta, Perfezionamento, Stato Pratiche) puoi cambiare intestazione, domande e testi, opzioni di risposta, salti fra le domande, domande saltabili e controlli sulle risposte. Il pannello impedisce i salvataggi che bloccherebbero gli agenti (salti mancanti, opzioni oltre il limite di WhatsApp, testi troppo lunghi).</p>
    <ol class="steps">
        <li><strong>Crea copia di prova</strong>: lavori su una bozza senza toccare la produzione.</li>
        <li>Modifica la copia. Chi ha il proprio numero WhatsApp associato al suo utente vede nel menu le voci <em>Prova: …</em> e può provare la bozza; le pratiche create sono segnate come Prova.</li>
        <li><strong>Pubblica in produzione</strong> quando va bene. <em>Rifai la copia di prova</em> riparte dalla produzione.</li>
    </ol>
    <p>Altri pulsanti: <em>Grafo delle domande</em> (diagramma del percorso) ed <em>Esporta configurazione</em> (ricrea il file di configurazione dalle tabelle).</p>
    <p>Nel percorso di richiesta, ogni domanda a scelta ha l'opzione <em>Modificabile nel preventivo</em>: se attiva, la domanda viene richiesta quando l'agente modifica un preventivo (le altre restano com'erano). Di default sono modificabili importo e durata. Conviene non rendere modificabili le domande che cambiano il percorso delle domande successive (per esempio prodotto o situazione lavorativa); se succede, il bot chiede comunque le domande nuove che il preventivo di partenza non aveva.</p>
    <p>Sulle domande di <strong>file</strong> si agganciano i controlli sul documento (tipo di documento, dati coerenti con quelli noti, estrazione dei dati, informativa firmata); se non ne scegli, valgono quelli predefiniti per il tipo di documento. Sulle domande di testo o a scelta si agganciano i controlli sulla risposta.</p>
    <h3>Documenti per il finanziamento</h3>
    <p>Il catalogo dei documenti per ogni tipo di finanziamento: nome (max 24 caratteri, è il titolo sulla lista WhatsApp), descrizione, tipo (obbligatorio, facoltativo, integrativo), tipo di lettura AI e ordine. Le modifiche valgono per le nuove pratiche.</p>
    <h3>Controlli sulle risposte</h3>
    <p>L'elenco dei controlli disponibili, con descrizione e dove sono usati. <em>Cerca nuovi controlli</em> registra le nuove classi aggiunte al programma. Un controllo usato da una domanda non si può disattivare.</p>

    <h2 id="anagrafiche">9. Anagrafiche</h2>
    <h3>Azienda</h3>
    <p>I dati dell'azienda titolare, che compaiono nell'informativa privacy pubblica, e le impostazioni operative:</p>
    <table>
        <tr><th>Campo</th><th>Effetto</th></tr>
        <tr><td>Ragione sociale, Sede, Email privacy, DPO</td><td>Titolare del trattamento nell'informativa e nella pagina di trasparenza.</td></tr>
        <tr><td>Telefono / Email del customer care</td><td>Indicati ai segnalatori occasionali.</td></tr>
        <tr><td>Email dell'istruttoria</td><td>Riceve preventivi e pratiche con allegati quando non c'è un CRM.</td></tr>
        <tr><td>URL del preventivatore</td><td>Se compilato, il preventivo usa il CRM; se vuoto, parte una email.</td></tr>
        <tr><td>URL dell'istruttoria</td><td>Se compilato, la pratica si invia al CRM; se vuoto, parte una email con dati e allegati.</td></tr>
        <tr><td>Conservazione pratiche perfezionate</td><td>Testo mostrato nell'informativa.</td></tr>
    </table>
    <h3>Produttori</h3>
    <p>Agenti e collaboratori. Si modificano con <em>Modifica</em> (non si creano da qui: arrivano dal gestionale o come segnalatori occasionali). Il numero di cellulare serve a riconoscere chi scrive su WhatsApp. I numeri sconosciuti compaiono qui come <em>Segnalatore occasionale</em>, non attivi: per convenzionarne uno, correggi i dati, imposta il tipo e spunta <em>Attivo</em>.</p>
    <h3>Utenti</h3>
    <p>Chi accede al pannello. Il campo <em>Numero WhatsApp</em> associa l'utente al suo telefono e abilita le voci di prova nel menu del bot.</p>

    <h2 id="invii">10. Invii a CRM e email</h2>
    <ul>
        <li><strong>Preventivo</strong> (fine della richiesta): con URL del preventivatore si calcolano gli importi; senza, email all'istruttoria.</li>
        <li><strong>Istruttoria</strong> (fine del perfezionamento): con URL dell'istruttoria la pratica va al CRM e deve rispondere con esito positivo (200); senza, parte la email con dati e allegati e la pratica si considera inviata solo se la email parte.</li>
        <li>Se l'invio fallisce l'agente può riprovare dal riepilogo; la pratica non risulta perfezionata finché non va a buon fine.</li>
    </ul>

    <h2 id="dati">11. Dati personali e conservazione</h2>
    <ul>
        <li>La richiesta è anonima; i dati personali si raccolgono solo dopo l'informativa firmata, conservata con data e ora.</li>
        <li>I dati personali sono cifrati nel database; i documenti stanno in un archivio privato non raggiungibile da internet.</li>
        <li>Le pratiche <strong>non perfezionate</strong> sono cancellate in automatico, con i loro file, dopo il periodo di conservazione (30 giorni di default). Eliminando una pratica dal pannello si cancellano anche documenti e conversazioni collegati.</li>
        <li>Per le richieste di cancellazione dei clienti vedi la pagina <a href="/cancellazione-dati">Cancellazione dei dati</a>; per i dettagli sul trattamento <a href="/privacy">Informativa privacy</a> e <a href="/compliance">Trasparenza e conformità</a>.</li>
    </ul>

    <h2 id="problemi">12. Se qualcosa non funziona</h2>
    <table>
        <tr><th>Sintomo</th><th>Cosa controllare</th></tr>
        <tr><td>Il bot non risponde</td><td>Dashboard → <em>Verifica WhatsApp</em>. Un token scaduto è la causa più comune: va rigenerato e aggiornato nella configurazione.</td></tr>
        <tr><td>I documenti restano “in attesa”</td><td>L'informativa non è verificata: guarda il documento <em>Informativa firmata</em> nella pratica e approvalo o chiedi un nuovo invio.</td></tr>
        <tr><td>I documenti non vengono letti</td><td>Dashboard → <em>Verifica AI</em>: chiave mancante o credito esaurito. Nel frattempo si controllano a mano.</td></tr>
        <tr><td>“Invio pratica fallito”</td><td>Controlla in Azienda l'email o l'URL dell'istruttoria e la configurazione della posta; poi l'agente riprova, oppure usa <em>Invia per email (forza)</em>.</td></tr>
        <tr><td>Le pratiche scadute non si cancellano</td><td>Nella dashboard la pulizia automatica risulta “Mai eseguita”: manca la pianificazione periodica sul server.</td></tr>
        <tr><td>Una domanda del bot è sbagliata</td><td>Percorsi di configurazione → copia di prova → correggi → provala dal menu → pubblica.</td></tr>
    </table>

    <p class="meta" style="margin-top:40px"><a href="#cose">↑ Torna all'inizio</a></p>
</div>
</body>
</html>
