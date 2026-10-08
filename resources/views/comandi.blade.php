<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Comandi</title>
    <meta name="description" content="Sintesi dei comandi che si possono usare nella chat WhatsApp di UnicoAgent.">
    <link rel="icon" href="/unicoagent_logo.png">
    <style>
        :root { --bg:#f6f8f7; --fg:#14211c; --muted:#55655e; --card:#fff; --line:#dbe4df; --brand:#0f7b5f; --soft:#e3f4ee; }
        @media (prefers-color-scheme: dark) { :root { --bg:#0e1512; --fg:#e8f0ec; --muted:#9bada5; --card:#16201b; --line:#26352e; --brand:#2bb48e; --soft:#14302a; } }
        * { box-sizing: border-box; }
        body { margin:0; background:var(--bg); color:var(--fg); font:16.5px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        a { color:var(--brand); }
        .wrap { max-width:640px; margin:0 auto; padding:0 16px 48px; }
        header.top { padding:16px 0; }
        header.top img { height:40px; }
        h1 { font-size:1.6rem; margin:8px 0 4px; }
        h2 { font-size:1.1rem; margin:28px 0 8px; }
        .meta { color:var(--muted); margin:0; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:14px; padding:4px 16px; }
        .row { display:flex; gap:12px; padding:10px 0; border-bottom:1px solid var(--line); }
        .row:last-child { border-bottom:0; }
        .row dt { flex:0 0 7.5rem; font-weight:600; }
        .row dd { margin:0; color:var(--muted); }
        kbd { background:var(--soft); border-radius:6px; padding:1px 7px; font-size:.95em; }
        .more { margin-top:24px; padding:12px 16px; background:var(--soft); border:1px solid var(--line); border-radius:12px; }
    </style>
</head>
<body>
<div class="wrap">
    <header class="top"><img src="/unicoagent_banner.png" alt="UnicoAgent"></header>

    <h1>Comandi</h1>
    <p class="meta">Si scrivono nella chat, anche con la barra (per esempio <kbd>/menu</kbd>), e valgono in qualsiasi momento.</p>

    <h2>Le voci del menu</h2>
    <dl class="card">
        @foreach (config('finanziamento.menu.options') as $title)
            <div class="row"><dt>{{ $title }}</dt></div>
        @endforeach
    </dl>

    <h2>Comandi utili</h2>
    <dl class="card">
        @foreach (config('finanziamento.profile.commands') as $name => $description)
            <div class="row"><dt><kbd>{{ $name }}</kbd></dt><dd>{{ $description }}</dd></div>
        @endforeach
    </dl>

    <p class="more">Per i dettagli di ogni passaggio c'è il <a href="/manuale#agente">manuale utente</a>, sezione «Per l'agente: WhatsApp».</p>
</div>
</body>
</html>
