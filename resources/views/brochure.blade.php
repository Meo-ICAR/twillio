<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>UnicoAgent · Brochure</title>
    <meta name="description" content="UnicoAgent: l'assistente WhatsApp che fa risparmiare tempo e risorse nella gestione delle pratiche di finanziamento, con controlli AI sui documenti e una rete di segnalatori.">
    <link rel="icon" href="/unicoagent_logo.png">
    <style>
        :root { --bg:#f4f7f6; --fg:#13201b; --muted:#56665f; --card:#fff; --line:#d9e3de; --brand:#0f7b5f; --brand2:#0a5a46; --soft:#e3f4ee; --accent:#f2a516; }
        @media (prefers-color-scheme: dark) { :root { --bg:#0d1411; --fg:#e8f0ec; --muted:#9bada5; --card:#16201b; --line:#26352e; --brand:#2bb48e; --brand2:#1b8a6c; --soft:#14302a; --accent:#f5b73d; } }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--bg); color:var(--fg); font:17px/1.6 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; }
        a { color:var(--brand); }
        .wrap { max-width:1040px; margin:0 auto; padding:0 22px; }
        header.top { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:16px 0; flex-wrap:wrap; }
        header.top img { height:42px; }
        .hero { background:linear-gradient(135deg,var(--brand2),var(--brand)); color:#fff; border-radius:22px; padding:44px 36px; margin:6px 0 10px; }
        .hero h1 { font-size:clamp(1.9rem,5vw,3rem); line-height:1.12; margin:0 0 12px; letter-spacing:-.02em; }
        .hero p { font-size:1.15rem; max-width:720px; margin:0 0 20px; opacity:.95; }
        .tags { display:flex; flex-wrap:wrap; gap:8px; }
        .tags span { background:rgba(255,255,255,.18); border:1px solid rgba(255,255,255,.35); border-radius:999px; padding:4px 14px; font-size:.92rem; }
        h2 { font-size:clamp(1.4rem,3.2vw,1.9rem); margin:54px 0 6px; letter-spacing:-.01em; }
        .lead { color:var(--muted); max-width:760px; margin:0 0 20px; }
        .grid { display:grid; gap:16px; grid-template-columns:repeat(auto-fit,minmax(230px,1fr)); }
        .card { background:var(--card); border:1px solid var(--line); border-radius:16px; padding:20px; }
        .card h3 { margin:6px 0 6px; font-size:1.08rem; }
        .card p { margin:0; color:var(--muted); font-size:.97rem; }
        .ico { font-size:1.7rem; }
        .vs { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
        @media (max-width:700px) { .vs { grid-template-columns:1fr; } .hero { padding:30px 22px; } }
        .vs .card.before { border-left:5px solid #c0392b; } .vs .card.after { border-left:5px solid var(--brand); }
        ul.check { list-style:none; padding:0; margin:8px 0 0; } ul.check li { padding-left:26px; position:relative; margin:6px 0; }
        ul.check li::before { content:"✓"; position:absolute; left:0; color:var(--brand); font-weight:700; }
        ul.cross li::before { content:"✗"; color:#c0392b; }
        .spot { background:var(--soft); border:1px solid var(--line); border-radius:20px; padding:28px; margin:18px 0; }
        .steps { counter-reset:s; display:grid; gap:12px; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); padding:0; list-style:none; margin:16px 0; }
        .steps li { counter-increment:s; background:var(--card); border:1px solid var(--line); border-radius:14px; padding:16px 16px 16px 54px; position:relative; font-size:.96rem; }
        .steps li::before { content:counter(s); position:absolute; left:14px; top:14px; width:28px; height:28px; border-radius:50%; background:var(--brand); color:#fff; display:grid; place-items:center; font-weight:700; }
        .calc { background:var(--card); border:1px solid var(--line); border-radius:18px; padding:24px; }
        .calc .row { display:grid; gap:14px; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); margin:10px 0 16px; }
        .calc label { display:block; font-size:.88rem; color:var(--muted); margin-bottom:4px; }
        .calc input { width:100%; font:inherit; padding:9px 12px; border-radius:10px; border:1px solid var(--line); background:var(--bg); color:var(--fg); }
        .out { display:flex; gap:26px; flex-wrap:wrap; align-items:baseline; }
        .out strong { font-size:2rem; color:var(--brand); line-height:1; } .out span { color:var(--muted); font-size:.92rem; display:block; }
        .note { color:var(--muted); font-size:.88rem; margin-top:10px; }
        .cta { text-align:center; background:var(--card); border:1px solid var(--line); border-radius:22px; padding:36px 22px; margin:50px 0 20px; }
        .btn { display:inline-block; background:var(--brand); color:#fff; text-decoration:none; font-weight:700; border-radius:12px; padding:12px 26px; margin:6px; }
        .btn.alt { background:transparent; color:var(--brand); border:2px solid var(--brand); }
        footer { color:var(--muted); font-size:.88rem; text-align:center; padding:10px 0 40px; }
        @media print { body { background:#fff; color:#000; font-size:12.5px; } .hero { color:#000; background:#e3f4ee; } .tags span { border-color:#0a5a46; } header.top a, .btn, .calc { display:none; } h2 { break-after:avoid; } .card, .spot, .steps li { break-inside:avoid; } }
    </style>
</head>
<body>
<div class="wrap">
    <header class="top">
        <img src="/unicoagent_banner.png" alt="UnicoAgent">
        <span><a href="/">Home</a> · <a href="/manuale">Manuale</a> · <a href="/compliance">Trasparenza</a></span>
    </header>

    <section class="hero">
        <h1>Meno tempo sulle pratiche.<br>Più tempo per chi porta lavoro.</h1>
        <p>UnicoAgent è l'ingresso WhatsApp del vostro CRM: raccoglie le richieste, legge e controlla i documenti con l'intelligenza artificiale e consegna pratiche già in ordine. Voi continuate a lavorare come sempre.</p>
        <div class="tags"><span>Nessun cambio di processo</span><span>Collegato al vostro CRM</span><span>Controlli AI sui documenti</span><span>Rete di segnalatori</span></div>
    </section>

    <section class="spot" style="margin-top:22px">
        <h2 style="margin-top:0">Accanto al vostro CRM, non al suo posto</h2>
        <p class="lead" style="margin-bottom:14px">Il vostro CRM, il vostro preventivatore e il vostro modo di istruire le pratiche restano quelli di oggi. UnicoAgent sta davanti: raccoglie e controlla, poi consegna.</p>
        <div class="grid">
            <div><h3>🔌 Si collega a ciò che avete</h3><p>Preventivo e pratica vanno ai vostri sistemi, fase per fase. Dove non c'è un CRM, arrivano per email all'istruttoria con dati e allegati.</p></div>
            <div><h3>🧭 Non decide e non scarta</h3><p>Nessun punteggio, nessuna pre-qualifica, nessun rifiuto automatico. Ogni richiesta arriva all'istruttoria, che decide come ha sempre fatto.</p></div>
            <div><h3>🛡️ L'investimento resta valido</h3><p>Niente migrazioni, niente formazione sul vostro gestionale da rifare. Cambia solo il modo in cui le pratiche vi arrivano: complete e controllate.</p></div>
        </div>
    </section>

    <h2>Il problema di ogni giorno</h2>
    <p class="lead">Dietro una pratica di finanziamento c'è molto lavoro che non è istruttoria: documenti illeggibili o sbagliati, dati ricopiati a mano, messaggi per chiedere «mi manca…», segnalazioni che arrivano da chiunque e vanno smistate.</p>
    <div class="vs">
        <div class="card before"><h3>Prima</h3>
            <ul class="check cross">
                <li>Documenti e dati sparsi tra chat, email e telefonate</li>
                <li>Istruttore che scopre solo a metà lavoro che manca una pagina o la firma</li>
                <li>Codici fiscali, IBAN e date ricopiati e sbagliati</li>
                <li>Segnalatori occasionali che assorbono tempo senza un percorso chiaro</li>
            </ul></div>
        <div class="card after"><h3>Con UnicoAgent</h3>
            <ul class="check">
                <li>Un solo canale d'ingresso, un codice pratica, uno storico ordinato</li>
                <li>I documenti sono controllati appena arrivano, mentre l'agente è ancora lì</li>
                <li>Dati validati mentre vengono scritti (codice fiscale, IBAN, maggiore età)</li>
                <li>Ogni segnalatore ha il suo percorso, senza impegnare il vostro team</li>
            </ul></div>
    </div>

    <h2>Dove si risparmia</h2>
    <p class="lead">Il risparmio non viene da un solo passaggio, ma dal togliere attese e rilavorazioni lungo tutta la pratica.</p>
    <div class="grid">
        <div class="card"><div class="ico">⏱️</div><h3>Pratiche complete al primo invio</h3><p>Il bot sa quali documenti servono per ogni prodotto e li chiede in ordine. Meno richiami, meno pratiche ferme in attesa di un allegato.</p></div>
        <div class="card"><div class="ico">⌨️</div><h3>Niente ricopiatura</h3><p>I dati letti dai documenti vengono proposti all'agente, che li conferma con un tocco. Le domande già note si saltano.</p></div>
        <div class="card"><div class="ico">🧑‍💼</div><h3>Istruttori sulle pratiche vere</h3><p>La dashboard mostra solo ciò che richiede intervento: documenti rifiutati, informative da verificare, pratiche da prendere in carico.</p></div>
        <div class="card"><div class="ico">🔁</div><h3>Consegna senza passaggi manuali</h3><p>Preventivo e pratica arrivano al vostro CRM, oppure per email con dati e allegati, senza che nessuno debba inoltrarli.</p></div>
        <div class="card"><div class="ico">🛠️</div><h3>Percorsi che cambiate da soli</h3><p>Domande, documenti e controlli si modificano dal pannello, con una copia di prova da collaudare prima di pubblicare.</p></div>
        <div class="card"><div class="ico">🗑️</div><h3>Archivio che si pulisce da solo</h3><p>Le pratiche non perfezionate vengono cancellate dopo il periodo di conservazione, senza lavoro di manutenzione.</p></div>
    </div>

    <h2>Controlli AI: errori trovati subito, non in istruttoria</h2>
    <p class="lead">L'intelligenza artificiale legge i documenti che l'agente invia, li confronta con i dati della pratica e avvisa subito l'agente se qualcosa non torna.</p>
    <div class="spot">
        <div class="grid">
            <div><h3>📄 È il documento giusto?</h3><p>Riconosce se la foto è una carta d'identità, una tessera sanitaria, una busta paga o altro, e se è leggibile.</p></div>
            <div><h3>🔗 I dati coincidono?</h3><p>Confronta cognome, nome, codice fiscale, numero e scadenza con quanto dichiarato e con gli altri documenti della stessa pratica.</p></div>
            <div><h3>✍️ L'informativa è firmata?</h3><p>Verifica che sia il vostro modulo e che sia firmato. Finché non lo è, nessun altro documento viene inviato all'AI.</p></div>
            <div><h3>🧾 Dati pronti da confermare</h3><p>Ciò che viene letto resta una proposta: vale solo quando l'agente la conferma.</p></div>
        </div>
        <ol class="steps">
            <li>L'agente invia la foto del documento</li>
            <li>Il bot risponde subito e l'agente continua a lavorare</li>
            <li>L'AI controlla in parallelo e scrive l'esito</li>
            <li>L'agente conferma i dati letti, con un tocco</li>
            <li>Prima dell'invio il sistema attende i controlli ancora in corso</li>
        </ol>
        <ul class="check">
            <li><strong>La decisione resta alle persone.</strong> L'AI non valuta il merito creditizio, non calcola punteggi e non scarta nessuna richiesta: legge e controlla i documenti, l'esito lo dà sempre un istruttore abilitato.</li>
            <li><strong>Privacy per progetto.</strong> La richiesta iniziale è anonima, i dati personali si raccolgono dopo l'informativa firmata e i documenti vanno all'AI solo dopo la sua verifica. I dati sono cifrati.</li>
            <li><strong>Sotto controllo.</strong> Dal pannello l'operatore può approvare o rifiutare ogni documento e vedere cosa ha letto l'AI e quando.</li>
        </ul>
    </div>

    <h2>Segnalatori: più canali di ingresso, senza più carico</h2>
    <p class="lead">Non tutti coloro che vi portano un cliente sono agenti convenzionati. UnicoAgent distingue chi scrive e adatta il percorso, così le segnalazioni occasionali non diventano lavoro per il vostro team.</p>
    <div class="grid">
        <div class="card"><div class="ico">🤝</div><h3>Produttori convenzionati</h3><p>Riconosciuti dal numero di cellulare, vengono salutati per nome. Completano richiesta e perfezionamento. Se avete un preventivatore collegato, l'importo che restituisce arriva direttamente a loro; altrimenti la richiesta va all'istruttoria.</p></div>
        <div class="card"><div class="ico">📣</div><h3>Segnalatori occasionali</h3><p>Chi non è in anagrafica può comunque segnalare: la richiesta è anonima, quindi non servono dati del cliente. Non riceve importi e viene invitato a contattare il vostro customer care, con i vostri recapiti.</p></div>
        <div class="card"><div class="ico">📇</div><h3>Anagrafica che cresce da sola</h3><p>Ogni nuovo numero viene registrato come segnalatore occasionale, non attivo: potete valutare chi merita di diventare produttore.</p></div>
        <div class="card"><div class="ico">🔒</div><h3>Informazioni al giusto livello</h3><p>Il perfezionamento e le risposte del preventivatore restano per chi è convenzionato; chi segnala vede solo ciò che serve.</p></div>
    </div>

    <h2>Stima il tuo risparmio</h2>
    <p class="lead">Un calcolo semplice, con i vostri numeri. I valori iniziali sono ipotesi da sostituire con quelli reali.</p>
    <div class="calc">
        <div class="row">
            <div><label for="n">Pratiche al mese</label><input id="n" type="number" min="0" value="100"></div>
            <div><label for="m">Minuti risparmiati per pratica (richiami, ricopiatura, controlli)</label><input id="m" type="number" min="0" value="20"></div>
            <div><label for="c">Costo orario del personale (€)</label><input id="c" type="number" min="0" value="25"></div>
        </div>
        <div class="out">
            <div><strong id="h">0</strong><span>ore risparmiate al mese</span></div>
            <div><strong id="e">0</strong><span>€ di costo evitato al mese</span></div>
            <div><strong id="y">0</strong><span>€ all'anno</span></div>
        </div>
        <p class="note">Stima indicativa basata solo sui valori inseriti: il risparmio reale dipende dal vostro modo di lavorare.</p>
    </div>

    <h2>Si integra con come lavorate</h2>
    <p class="lead">Per ogni fase scegliete voi dove vanno i dati: al vostro sistema oppure per email. Si cambia dalla scheda azienda, senza interventi sul software.</p>
    <div class="grid">
        <div class="card"><h3>Il vostro CRM, fase per fase</h3><p>Un collegamento per il preventivo e uno per l'istruttoria. Dove non c'è un sistema, la fase viaggia per email all'istruttoria, con tutti gli allegati. Se l'invio non riesce, l'agente lo sa e può riprovare: la pratica non risulta inviata finché non è arrivata.</p></div>
        <div class="card"><h3>Pannello di controllo</h3><p>Pratiche, documenti, conversazioni, produttori e configurazione in un unico pannello, con una dashboard che dice cosa fare adesso.</p></div>
        <div class="card"><h3>Pronto per più aziende</h3><p>L'architettura è predisposta per servire più società dalla stessa installazione.</p></div>
    </div>

    <section class="cta">
        <h2 style="margin-top:0">Vediamolo sulle vostre pratiche</h2>
        <p class="lead" style="margin:0 auto 14px">Una demo di mezz'ora con i vostri prodotti e i vostri documenti.</p>
        <a class="btn" href="https://www.hassisto.com/it/">Contattaci su hassisto.com</a>
        <a class="btn alt" href="/manuale">Leggi il manuale</a>
        <a class="btn alt" href="/grafo-domande">Guarda le domande del bot</a>
    </section>
    <footer>UnicoAgent · assistente WhatsApp per pratiche di finanziamento è un prodotto <a href="https://www.hassisto.com/it/">Hassisto</a> · <a href="/privacy">Privacy</a> · <a href="/compliance">Trasparenza e conformità</a></footer>
</div>
<script>
    const $ = (id) => document.getElementById(id);
    const fmt = (v) => Math.round(v).toLocaleString('it-IT');
    function calc() {
        const n = +$('n').value || 0, m = +$('m').value || 0, c = +$('c').value || 0;
        const hours = n * m / 60, euro = hours * c;
        $('h').textContent = fmt(hours); $('e').textContent = fmt(euro); $('y').textContent = fmt(euro * 12);
    }
    ['n', 'm', 'c'].forEach((id) => $(id).addEventListener('input', calc));
    calc();
</script>
</body>
</html>
