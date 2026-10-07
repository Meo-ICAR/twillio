@php
    $ph = fn ($value) => filled($value) ? $value : '[da completare]';
    $responsabile = config('privacy.responsabile.nome');
    $giorni = (int) config('privacy.retention_days');
@endphp
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Informativa privacy</title>
    <meta name="description" content="Informativa sul trattamento dei dati personali raccolti tramite il servizio di richiesta e perfezionamento dei finanziamenti su WhatsApp.">
    <style>
        :root { --bg:#f6f8f7; --fg:#14211c; --muted:#55655e; --card:#ffffff; --line:#dbe4df; --brand:#0f7b5f; --brand-soft:#e3f4ee; }
        @media (prefers-color-scheme: dark) {
            :root { --bg:#0e1512; --fg:#e8f0ec; --muted:#9bada5; --card:#16201b; --line:#26352e; --brand:#2bb48e; --brand-soft:#14302a; }
        }
        * { box-sizing: border-box; }
        body { margin:0; background:var(--bg); color:var(--fg); font:17px/1.65 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        a { color:var(--brand); }
        .wrap { max-width:820px; margin:0 auto; padding:0 20px; }
        header.top { display:flex; justify-content:space-between; align-items:center; padding:18px 0; gap:12px; flex-wrap:wrap; }
        .logo { font-weight:700; text-decoration:none; color:var(--fg); }
        .logo span { color:var(--brand); }
        .btn { display:inline-block; padding:8px 16px; border-radius:10px; font:inherit; font-size:.95rem; font-weight:600; text-decoration:none; border:1px solid var(--line); background:var(--card); color:var(--fg); cursor:pointer; }
        h1 { font-size:clamp(1.7rem, 4vw, 2.3rem); line-height:1.2; letter-spacing:-.015em; margin:12px 0 6px; }
        h2 { font-size:1.2rem; margin:34px 0 8px; }
        .meta { color:var(--muted); font-size:.92rem; margin:0 0 22px; }
        .box { background:var(--brand-soft); border:1px solid var(--line); border-radius:14px; padding:16px 20px; margin:18px 0; }
        .box p { margin:6px 0; }
        ul { padding-left:22px; margin:8px 0; }
        li { margin:4px 0; }
        .sign { background:var(--card); border:1px solid var(--line); border-radius:14px; padding:20px; margin-top:34px; }
        .sign .lines { display:grid; grid-template-columns:1fr 1fr; gap:28px; margin-top:34px; }
        .sign .line { border-top:1px solid var(--fg); padding-top:6px; font-size:.9rem; color:var(--muted); }
        footer { padding:30px 0 44px; color:var(--muted); font-size:.88rem; }
        @media (max-width:600px) { .sign .lines { grid-template-columns:1fr; } }
        @media print {
            :root { --bg:#fff; --fg:#000; --muted:#333; --card:#fff; --line:#999; --brand:#000; --brand-soft:#fff; }
            body { font-size:11.5pt; }
            header.top, footer { display:none; }
            h2 { break-after:avoid; }
            .sign { break-inside:avoid; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <header class="top">
        <a class="logo" href="/">Unico<span>Agent</span></a>
        <button class="btn" type="button" onclick="window.print()">Stampa</button>
    </header>

    <main>
        <h1>Informativa sul trattamento dei dati personali</h1>
        <p class="meta">Ai sensi degli artt. 13 e 14 del Regolamento (UE) 2016/679 (GDPR) · Ultimo aggiornamento: {{ config('privacy.updated_at') }}</p>

        <p>Questa informativa spiega come vengono trattati i dati personali raccolti attraverso il servizio di richiesta e perfezionamento dei finanziamenti, usato dagli agenti tramite WhatsApp.</p>

        <h2>1. Titolare del trattamento</h2>
        <div class="box">
            <p><strong>Titolare del trattamento:</strong> {{ $ph($company?->name) }}</p>
            <p><strong>Sede:</strong> {{ $ph($company?->address) }}</p>
            <p><strong>Contatto per la privacy:</strong> {{ $ph($company?->email) }}</p>
            @if (filled($company?->dpo_email))
                <p><strong>Responsabile della protezione dei dati (DPO):</strong> {{ $company->dpo_email }}</p>
            @endif
        </div>
        <p>Il Titolare è l'azienda per cui l'agente opera: decide perché e come i dati vengono trattati.</p>

        <h2>2. Responsabile del trattamento</h2>
        <p>Il Titolare ha nominato <strong>{{ $responsabile }}</strong> Responsabile del trattamento ai sensi dell'art. 28 del GDPR. {{ $responsabile }} si occupa esclusivamente del <strong>backup</strong> dei dati e <strong>non ha accesso ai dati</strong> personali trattati: non può leggerli né utilizzarli per alcuna finalità propria.</p>

        <h2>3. Quali dati trattiamo</h2>
        <p>Il servizio ha due fasi.</p>
        <ul>
            <li><strong>Richiesta:</strong> dati di profilo <em>non identificativi</em>, indicati a fasce (prodotto richiesto, importo, durata, situazione lavorativa, reddito, impegni in corso). In questa fase non vengono raccolti nome, codice fiscale, recapiti o dati dell'azienda.</li>
            <li><strong>Perfezionamento:</strong> solo dopo la ricezione di questa informativa firmata, vengono raccolti dati anagrafici (nome, cognome, data e luogo di nascita, residenza, stato civile), codice fiscale, estremi del documento d'identità, telefono, email, IBAN, dati del rapporto di lavoro o dell'attività e, per le aziende, ragione sociale e partita IVA. Vengono inoltre acquisite copie dei documenti (identità, codice fiscale, reddito).</li>
        </ul>

        <h2>4. Finalità e base giuridica</h2>
        <ul>
            <li>Istruire e gestire la richiesta di finanziamento presentata dall'interessato (art. 6, par. 1, lett. b, GDPR: misure precontrattuali adottate su richiesta dell'interessato).</li>
            <li>Adempiere agli obblighi previsti dalla legge per l'attività di mediazione creditizia (art. 6, par. 1, lett. c, GDPR).</li>
        </ul>
        <p>Il conferimento dei dati della fase di perfezionamento è facoltativo, ma senza di essi non è possibile istruire la pratica.</p>

        <h2>5. Come vengono raccolti i dati</h2>
        <p>I dati sono inseriti dall'agente, su indicazione dell'interessato, nella conversazione WhatsApp con il servizio. L'agente riceve un codice pratica e può proseguire con i dati personali soltanto dopo aver inviato la presente informativa firmata. Ogni dato viene controllato al momento dell'inserimento (ad esempio formato del codice fiscale, maggiore età, validità dell'IBAN).</p>

        <h2>6. Destinatari dei dati</h2>
        <ul>
            <li>Il personale autorizzato del Titolare, tramite un'area riservata ad accesso protetto.</li>
            <li>I soggetti (banche e intermediari finanziari) ai quali il Titolare, su richiesta dell'interessato, presenta la richiesta di finanziamento.</li>
            <li>{{ $responsabile }}, in qualità di Responsabile, per le sole copie di backup e senza accesso ai dati.</li>
            <li>Il fornitore del servizio WhatsApp (Meta Platforms), per il trasporto dei messaggi scambiati con il servizio. Questo può comportare trasferimenti verso Paesi extra SEE, regolati dalle garanzie adottate dal fornitore.</li>
        </ul>

        <h2>7. Sicurezza</h2>
        <p>I dati e i documenti sono conservati su server sicuri, in forma cifrata. L'accesso è riservato a utenti autorizzati e i documenti non sono raggiungibili da internet.</p>

        <h2>8. Conservazione</h2>
        <ul>
            <li><strong>Pratica non perfezionata:</strong> i dati e i documenti sono cancellati automaticamente dopo <strong>{{ $giorni }} giorni</strong> dalla richiesta.</li>
            <li><strong>Pratica perfezionata:</strong> {{ $ph($company?->retention_perfected) }}</li>
        </ul>

        <h2>9. Diritti dell'interessato</h2>
        <p>Puoi chiedere al Titolare, in qualsiasi momento, l'accesso ai tuoi dati, la rettifica, la cancellazione, la limitazione del trattamento, la portabilità e puoi opporti al trattamento (artt. 15-22 GDPR), scrivendo al contatto indicato al punto 1. Hai inoltre il diritto di proporre reclamo al Garante per la protezione dei dati personali (<a href="https://www.garanteprivacy.it">www.garanteprivacy.it</a>).</p>

        <h2>10. Decisioni automatizzate</h2>
        <p>Il servizio raccoglie e ordina le informazioni: non prende decisioni sul finanziamento né effettua profilazione. Le valutazioni sulla pratica sono svolte da persone.</p>

        <div class="sign">
            <strong>Per presa visione</strong>
            <p style="margin:6px 0 0">Dichiaro di aver letto l'informativa sul trattamento dei dati personali.</p>
            <div class="lines">
                <div class="line">Nome e cognome</div>
                <div class="line">Data e firma</div>
            </div>
        </div>
    </main>

    <footer>
        <a href="/">← Torna alla home</a>
    </footer>
</div>
</body>
</html>
