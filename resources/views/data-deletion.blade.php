@php
    $ph = fn ($value) => filled($value) ? $value : '[da completare]';
    $responsabile = config('privacy.responsabile.nome');
    $giorni = (int) config('privacy.retention_days');
    $email = $company?->email;
@endphp
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cancellazione dei dati</title>
    <link rel="icon" href="/unicoagent_logo.png">
    <meta name="description" content="Come chiedere la cancellazione dei dati personali trattati nel servizio di richiesta e perfezionamento dei finanziamenti su WhatsApp.">
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
        ol, ul { padding-left:22px; margin:8px 0; }
        li { margin:6px 0; }
        footer { padding:30px 0 44px; color:var(--muted); font-size:.88rem; }
        @media print { header.top, footer { display:none; } body { font-size:11.5pt; } }
    </style>
</head>
<body>
<div class="wrap">
    <header class="top">
        <a class="logo" href="/">Unico<span>Agent</span></a>
        <a class="btn" href="/privacy">Informativa privacy</a>
    </header>

    <main>
        <h1>Come chiedere la cancellazione dei tuoi dati</h1>
        <p class="meta">Diritto alla cancellazione, art. 17 del Regolamento (UE) 2016/679 (GDPR) · Ultimo aggiornamento: {{ config('privacy.updated_at') }}</p>

        <p>Se un agente ha inserito i tuoi dati nel servizio per una richiesta di finanziamento, puoi chiedere che vengano cancellati. La richiesta si fa al Titolare del trattamento, l'azienda per cui l'agente opera.</p>

        <h2>1. A chi scrivere</h2>
        <div class="box">
            <p><strong>Titolare del trattamento:</strong> {{ $ph($company?->name) }}</p>
            <p><strong>Contatto per la privacy:</strong>
                @if (filled($email))
                    <a href="mailto:{{ $email }}">{{ $email }}</a>
                @else
                    [da completare]
                @endif
            </p>
        </div>

        <h2>2. Cosa scrivere</h2>
        <ol>
            <li>Il tuo nome e cognome, e un recapito per ricevere la risposta.</li>
            <li>Il <strong>codice della pratica</strong> (formato <strong>FIN-AAAA-NNNN</strong>), che l'agente ha ricevuto quando ha aperto la richiesta. Se non lo hai, chiedilo all'agente: ci permette di trovare subito i tuoi dati.</li>
            <li>La frase «Chiedo la cancellazione dei miei dati personali».</li>
        </ol>
        <p>Per proteggere i tuoi dati, il Titolare può chiederti di confermare la tua identità prima di procedere.</p>

        <h2>3. Cosa succede dopo</h2>
        <ol>
            <li>Il Titolare verifica la richiesta e la pratica a cui si riferisce.</li>
            <li>Il personale autorizzato elimina la pratica dal pannello riservato: con essa vengono eliminati i dati personali, i <strong>documenti</strong> caricati, le <strong>annotazioni</strong> e le conversazioni ancora aperte collegate.</li>
            <li>Ricevi una conferma entro <strong>un mese</strong> dalla richiesta, come prevede il GDPR (art. 12), prorogabile solo nei casi previsti dalla legge.</li>
        </ol>

        <h2>4. Cosa viene cancellato e cosa può restare</h2>
        <ul>
            <li><strong>Pratiche non perfezionate:</strong> si cancellano comunque in automatico dopo {{ $giorni }} giorni dalla richiesta, anche senza che tu faccia nulla.</li>
            <li><strong>Pratiche perfezionate:</strong> alcuni dati possono dover essere conservati per obblighi di legge. In questo caso restano solo per il tempo richiesto e solo per quelle finalità. Conservazione prevista: {{ $ph($company?->retention_perfected) }}</li>
            <li><strong>Copie di backup:</strong> sono gestite da {{ $responsabile }}, Responsabile del trattamento, che non ha accesso ai dati. Contengono dati cifrati e non vengono consultate: si eliminano alla scadenza del ciclo di conservazione dei backup.</li>
        </ul>

        <h2>5. I messaggi nella chat di WhatsApp</h2>
        <p>I messaggi scambiati con il servizio restano anche nella chat di WhatsApp dell'agente e nei sistemi di WhatsApp (Meta Platforms), che li gestisce con le proprie regole. Puoi chiedere all'agente di eliminare la conversazione dal suo telefono. Per i dati che WhatsApp tratta come titolare autonomo valgono le sue condizioni e i suoi strumenti.</p>

        <h2>6. Se non sei soddisfatto della risposta</h2>
        <p>Hai il diritto di proporre reclamo al Garante per la protezione dei dati personali (<a href="https://www.garanteprivacy.it">www.garanteprivacy.it</a>). Gli altri diritti (accesso, rettifica, limitazione, portabilità, opposizione) si esercitano con la stessa procedura: leggi l'<a href="/privacy">informativa privacy</a>.</p>
    </main>

    <footer>
        <a href="/">← Torna alla home</a> · <a href="/privacy">Informativa privacy</a> · <a href="/compliance">Trasparenza e conformità</a>
    </footer>
</div>
</body>
</html>
