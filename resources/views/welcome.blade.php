<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pratiche di finanziamento su WhatsApp, senza dati dei clienti in chat</title>
    <meta name="description" content="Gli agenti aprono e completano le pratiche di finanziamento direttamente su WhatsApp. La richiesta è anonima; i dati personali arrivano solo dopo l'informativa privacy firmata.">
    <style>
        :root {
            --bg: #f6f8f7; --fg: #14211c; --muted: #55655e; --card: #ffffff; --line: #dbe4df;
            --brand: #0f7b5f; --brand-fg: #ffffff; --brand-soft: #e3f4ee; --accent: #b4540a;
            --wa-bg: #e7ddd3; --wa-in: #ffffff; --wa-out: #d9fdd3;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0e1512; --fg: #e8f0ec; --muted: #9bada5; --card: #16201b; --line: #26352e;
                --brand: #2bb48e; --brand-fg: #04120d; --brand-soft: #14302a; --accent: #f0a15c;
                --wa-bg: #0b141a; --wa-in: #202c33; --wa-out: #005c4b;
            }
        }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body { margin: 0; background: var(--bg); color: var(--fg); font: 17px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        a { color: var(--brand); }
        .wrap { max-width: 1080px; margin: 0 auto; padding: 0 20px; }
        header.top { display: flex; justify-content: space-between; align-items: center; padding: 18px 0; }
        .logo { font-weight: 700; font-size: 1.1rem; letter-spacing: -.01em; }
        .logo span { color: var(--brand); }
        .btn { display: inline-block; padding: 12px 22px; border-radius: 10px; font-weight: 600; text-decoration: none; border: 1px solid transparent; }
        .btn.primary { background: var(--brand); color: var(--brand-fg); }
        .btn.ghost { border-color: var(--line); color: var(--fg); background: var(--card); }
        .btn.small { padding: 8px 16px; font-size: .95rem; }
        .hero { display: grid; grid-template-columns: 1.1fr .9fr; gap: 40px; align-items: center; padding: 40px 0 64px; }
        .eyebrow { display: inline-block; background: var(--brand-soft); color: var(--brand); font-weight: 600; font-size: .85rem; padding: 4px 12px; border-radius: 999px; }
        h1 { font-size: clamp(2rem, 4.6vw, 3.1rem); line-height: 1.12; letter-spacing: -.02em; margin: 14px 0 16px; }
        .lead { font-size: 1.15rem; color: var(--muted); margin: 0 0 26px; }
        .cta { display: flex; flex-wrap: wrap; gap: 12px; }
        .note { color: var(--muted); font-size: .9rem; margin-top: 14px; }
        .phone { background: var(--wa-bg); border: 1px solid var(--line); border-radius: 22px; padding: 18px 14px; box-shadow: 0 18px 40px rgba(0,0,0,.12); }
        .phone .bar { font-weight: 600; font-size: .9rem; padding: 0 6px 10px; color: var(--fg); }
        .msg { max-width: 86%; padding: 8px 12px; border-radius: 12px; margin: 6px 0; font-size: .92rem; line-height: 1.4; color: #111; }
        @media (prefers-color-scheme: dark) { .msg { color: #e9edef; } }
        .msg.in { background: var(--wa-in); border-top-left-radius: 2px; }
        .msg.out { background: var(--wa-out); margin-left: auto; border-top-right-radius: 2px; }
        .msg small { display: block; opacity: .65; font-size: .75rem; }
        section { padding: 56px 0; border-top: 1px solid var(--line); }
        h2 { font-size: clamp(1.5rem, 3vw, 2rem); line-height: 1.2; letter-spacing: -.015em; margin: 0 0 10px; }
        .sub { color: var(--muted); margin: 0 0 32px; max-width: 640px; }
        .grid { display: grid; gap: 18px; }
        .grid.three { grid-template-columns: repeat(3, 1fr); }
        .grid.two { grid-template-columns: repeat(2, 1fr); }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 22px; }
        .card h3 { margin: 0 0 6px; font-size: 1.05rem; }
        .card p { margin: 0; color: var(--muted); font-size: .97rem; }
        .step-n { display: inline-grid; place-items: center; width: 34px; height: 34px; border-radius: 50%; background: var(--brand); color: var(--brand-fg); font-weight: 700; margin-bottom: 12px; }
        .tags { display: flex; flex-wrap: wrap; gap: 10px; }
        .tag { background: var(--card); border: 1px solid var(--line); border-radius: 999px; padding: 8px 16px; font-weight: 500; }
        .privacy { background: var(--brand-soft); border: 1px solid var(--line); border-radius: 18px; padding: 32px; }
        .privacy ul { margin: 14px 0 0; padding-left: 20px; }
        .privacy li { margin: 6px 0; }
        .compare { width: 100%; border-collapse: collapse; background: var(--card); border: 1px solid var(--line); border-radius: 14px; overflow: hidden; }
        .compare th, .compare td { text-align: left; padding: 14px 18px; border-bottom: 1px solid var(--line); vertical-align: top; font-size: .97rem; }
        .compare th { background: var(--brand-soft); }
        .compare tr:last-child td { border-bottom: 0; }
        .final { text-align: center; }
        .final p { color: var(--muted); max-width: 560px; margin: 0 auto 24px; }
        footer { padding: 28px 0 40px; color: var(--muted); font-size: .88rem; border-top: 1px solid var(--line); }
        @media (max-width: 820px) {
            .hero { grid-template-columns: 1fr; padding-top: 16px; }
            .grid.three, .grid.two { grid-template-columns: 1fr; }
            .privacy { padding: 22px; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <header class="top">
        <div class="logo">Unico<span>Agent</span></div>
        <a class="btn ghost small" href="/admin">Area riservata</a>
    </header>

    <div class="hero">
        <div>
            <span class="eyebrow">Per mediatori creditizi e reti di agenti</span>
            <h1>Le pratiche di finanziamento si aprono su WhatsApp. I dati dei clienti, solo quando servono.</h1>
            <p class="lead">I tuoi agenti scrivono dal telefono che già usano ogni giorno. Un assistente li guida con le domande giuste, assegna un codice pratica e raccoglie i dati personali del cliente solo dopo che l'informativa privacy firmata è arrivata.</p>
            <div class="cta">
                <a class="btn primary" href="#come-funziona">Come funziona</a>
                <a class="btn ghost" href="/admin">Accedi al pannello</a>
            </div>
            <p class="note">Si collega al tuo numero WhatsApp Business. Gli agenti non installano nulla.</p>
        </div>

        <div class="phone" aria-label="Esempio di conversazione">
            <div class="bar">Servizio agenti</div>
            <div class="msg out">Richiedi Finanziamento<small>agente</small></div>
            <div class="msg in">Che tipo di finanziamento vuoi richiedere?<small>assistente · lista di scelte</small></div>
            <div class="msg out">Cessione del quinto</div>
            <div class="msg in">Qual è la situazione lavorativa del cliente?</div>
            <div class="msg out">Dipendente pubblico</div>
            <div class="msg in">✅ Richiesta registrata.<br>Codice pratica: <strong>FIN-2026-0001</strong><br>Conservalo: ti servirà per perfezionare il finanziamento.</div>
        </div>
    </div>
</div>

<div class="wrap">
    <section>
        <h2>Il problema che risolve</h2>
        <p class="sub">Oggi le pratiche passano per chat, fogli e telefonate. I dati dei clienti finiscono ovunque, e a ogni agente servono le stesse domande, poste ogni volta in modo diverso.</p>
        <div class="grid three">
            <div class="card"><h3>Informazioni incomplete</h3><p>Le richieste arrivano a pezzi e in ordine sparso. Il backoffice perde tempo a ricostruirle.</p></div>
            <div class="card"><h3>Dati personali dispersi</h3><p>Codici fiscali, IBAN e documenti scambiati in chat personali, senza un archivio ordinato.</p></div>
            <div class="card"><h3>Nessuna visibilità</h3><p>Non è chiaro a che punto sia una pratica, né chi abbia mandato cosa e quando.</p></div>
        </div>
    </section>

    <section id="come-funziona">
        <h2>Come funziona</h2>
        <p class="sub">Due momenti distinti, collegati da un codice pratica.</p>
        <div class="grid three">
            <div class="card"><div class="step-n">1</div><h3>Richiedi Finanziamento</h3><p>L'agente risponde a poche domande di profilo, a scelta multipla: prodotto, importo, durata, situazione lavorativa. Nessun nome, nessun codice fiscale. Alla fine riceve il codice pratica.</p></div>
            <div class="card"><div class="step-n">2</div><h3>Informativa privacy</h3><p>Per perfezionare, l'agente inserisce il codice e invia l'informativa firmata dal cliente, in foto o PDF. Solo a quel punto l'assistente apre la raccolta dei dati personali.</p></div>
            <div class="card"><div class="step-n">3</div><h3>Perfeziona Finanziamento</h3><p>Anagrafica, documento d'identità, IBAN, dati lavorativi e documenti. Ogni dato è controllato mentre viene scritto, con un riepilogo da confermare prima di chiudere.</p></div>
        </div>
        <p style="margin-top:22px"><a class="btn ghost" href="/grafo-domande">Guarda tutte le domande del bot →</a></p>
    </section>

    <section>
        <h2>Cosa ottieni</h2>
        <div class="grid three">
            <div class="card"><h3>Domande giuste, sempre</h3><p>Un percorso diverso per ogni prodotto, con salti automatici in base alle risposte. Gli agenti non dimenticano più niente.</p></div>
            <div class="card"><h3>Dati controllati all'ingresso</h3><p>Codice fiscale, date, telefono, email e IBAN vengono verificati subito. Il cliente deve essere maggiorenne e l'IBAN deve avere un checksum valido.</p></div>
            <div class="card"><h3>Pannello per il backoffice</h3><p>Elenco delle pratiche con filtri, scheda con risposte leggibili, allegati scaricabili e stato aggiornabile. Le conversazioni in corso si consultano senza vedere dati personali.</p></div>
            <div class="card"><h3>Comandi semplici</h3><p>In ogni momento l'agente può scrivere «indietro», «menu» o «annulla». Se riprende dopo più di un giorno, il bot gli chiede se continuare.</p></div>
            <div class="card"><h3>Stato delle pratiche</h3><p>Con «Stato Pratiche» ogni agente rivede le proprie ultime pratiche e a che punto sono. Nessuno vede quelle degli altri.</p></div>
            <div class="card"><h3>Nessuna app da installare</h3><p>Funziona dentro WhatsApp, con liste e pulsanti. Gli agenti sono operativi dal primo messaggio.</p></div>
        </div>
    </section>

    <section>
        <h2>Prodotti coperti</h2>
        <p class="sub">Un percorso di domande dedicato per ciascuno, con prevalenza sul credito al consumo.</p>
        <div class="tags">
            <span class="tag">Prestito personale</span>
            <span class="tag">Cessione del quinto</span>
            <span class="tag">Finalizzato (auto, moto, beni)</span>
            <span class="tag">Mutuo</span>
            <span class="tag">Leasing</span>
            <span class="tag">Finanziamento aziendale</span>
        </div>
    </section>

    <section>
        <div class="privacy">
            <h2>Pensato per ridurre i dati personali in circolazione</h2>
            <p class="sub" style="margin-bottom:0">La privacy non è un modulo aggiunto alla fine: decide in che ordine il bot chiede le cose.</p>
            <ul>
                <li><strong>Richiesta anonima:</strong> in fase 1 il bot non chiede nulla che identifichi il cliente o la sua azienda, e rifiuta se l'agente prova a scrivere un codice fiscale, un telefono o un'email.</li>
                <li><strong>Informativa prima dei dati:</strong> i dati personali si raccolgono solo dopo la ricezione dell'informativa firmata, che resta archiviata con data e ora.</li>
                <li><strong>Dati cifrati:</strong> i dati personali sono cifrati nel database e i documenti vengono salvati in un archivio privato, non raggiungibile da internet.</li>
                <li><strong>Cancellazione ordinata:</strong> eliminando una pratica dal pannello si eliminano anche i suoi file.</li>
            </ul>
        </div>
    </section>

    <section>
        <h2>Prima e dopo</h2>
        <table class="compare">
            <thead><tr><th></th><th>Oggi</th><th>Con il servizio</th></tr></thead>
            <tbody>
                <tr><td><strong>Raccolta dati</strong></td><td>Messaggi liberi, in ordine diverso ogni volta</td><td>Domande guidate, validate e complete</td></tr>
                <tr><td><strong>Dati dei clienti</strong></td><td>In chat personali e fogli sparsi</td><td>Nel database, cifrati, con accesso al solo pannello</td></tr>
                <tr><td><strong>Informativa privacy</strong></td><td>Gestita a parte, spesso dopo</td><td>Richiesta prima di ogni dato personale</td></tr>
                <tr><td><strong>Tracciabilità</strong></td><td>Nessuna</td><td>Codice pratica, stato e allegati per ogni cliente</td></tr>
            </tbody>
        </table>
    </section>

    <section class="final">
        <h2>Vuoi vederlo con le tue pratiche?</h2>
        <p>Il servizio si collega al tuo numero WhatsApp Business e si adatta ai tuoi prodotti: le domande si modificano in un file di configurazione, senza toccare il codice.</p>
        <a class="btn primary" href="/admin">Accedi al pannello</a>
    </section>

    <footer>
        <div>UnicoAgent · assistente WhatsApp per pratiche di finanziamento.</div>
        <div><a href="/grafo-domande">Grafo delle domande</a> · <a href="/privacy">Informativa privacy</a></div>
        <div>Il servizio non sostituisce gli obblighi informativi e contrattuali del mediatore: i testi dell'informativa e dei consensi restano quelli forniti dal tuo ufficio legale.</div>
    </footer>
</div>
</body>
</html>
