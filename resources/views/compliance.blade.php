@php
    $ph = fn ($value) => filled($value) ? $value : '[da completare]';
    $responsabile = config('privacy.responsabile.nome');
    $giorni = (int) config('privacy.retention_days');
    $statusLabel = ['ottenuta' => 'Ottenuta', 'in_corso' => 'In corso', 'prevista' => 'Prevista', 'attivo' => 'Attivo', 'previsto' => 'Previsto'];
    $certifications = config('privacy.certifications');
    $aiActive = filled(config('services.anthropic.key'));
    $subprocessors = collect(config('privacy.subprocessors'))->map(function ($sub) {
        if (isset($sub['active_when'])) {
            $sub['status'] = filled(config($sub['active_when'])) ? 'attivo' : 'previsto';
        }

        return $sub;
    })->all();
@endphp
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Trasparenza e conformità</title>
    <meta name="description" content="Come vengono trattati i dati nel servizio di richiesta e perfezionamento dei finanziamenti su WhatsApp: flusso dei dati, ruoli, sub-responsabili, sicurezza e uso dell'intelligenza artificiale.">
    <style>
        :root { --bg:#f6f8f7; --fg:#14211c; --muted:#55655e; --card:#ffffff; --line:#dbe4df; --brand:#0f7b5f; --brand-soft:#e3f4ee; --warn:#b4540a; --warn-soft:#fff1e3; }
        @media (prefers-color-scheme: dark) {
            :root { --bg:#0e1512; --fg:#e8f0ec; --muted:#9bada5; --card:#16201b; --line:#26352e; --brand:#2bb48e; --brand-soft:#14302a; --warn:#f0a15c; --warn-soft:#33220f; }
        }
        * { box-sizing: border-box; }
        body { margin:0; background:var(--bg); color:var(--fg); font:17px/1.65 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        a { color:var(--brand); }
        .wrap { max-width:960px; margin:0 auto; padding:0 20px; }
        header.top { display:flex; justify-content:space-between; align-items:center; padding:18px 0; gap:12px; flex-wrap:wrap; }
        .logo { font-weight:700; text-decoration:none; color:var(--fg); }
        .logo span { color:var(--brand); }
        .btn { display:inline-block; padding:8px 16px; border-radius:10px; font:inherit; font-size:.95rem; font-weight:600; text-decoration:none; border:1px solid var(--line); background:var(--card); color:var(--fg); cursor:pointer; }
        h1 { font-size:clamp(1.8rem, 4vw, 2.4rem); line-height:1.2; letter-spacing:-.015em; margin:12px 0 6px; }
        h2 { font-size:1.35rem; margin:0 0 10px; }
        h2 .n { color:var(--brand); font-variant-numeric:tabular-nums; margin-right:8px; }
        .meta { color:var(--muted); font-size:.92rem; margin:0 0 8px; }
        .lead { color:var(--muted); max-width:680px; }
        section { padding:34px 0; border-top:1px solid var(--line); }
        ul { padding-left:22px; margin:8px 0; }
        li { margin:5px 0; }
        .grid { display:grid; gap:16px; grid-template-columns:repeat(3, 1fr); }
        .card { background:var(--card); border:1px solid var(--line); border-radius:14px; padding:18px 20px; }
        .card h3 { margin:0 0 6px; font-size:1.02rem; }
        .card p { margin:0; color:var(--muted); font-size:.96rem; }
        .flow { display:flex; align-items:stretch; gap:10px; flex-wrap:wrap; margin:18px 0 8px; }
        .flow .step { flex:1 1 150px; background:var(--card); border:1px solid var(--line); border-radius:12px; padding:12px 14px; font-size:.93rem; }
        .flow .step b { display:block; margin-bottom:2px; }
        .flow .step.planned { border-style:dashed; color:var(--muted); }
        .flow .arrow { align-self:center; color:var(--muted); font-size:1.3rem; }
        .branches { display:grid; gap:10px; grid-template-columns:1fr 1fr; flex:1 1 260px; }
        table { width:100%; border-collapse:collapse; background:var(--card); border:1px solid var(--line); border-radius:12px; overflow:hidden; }
        th, td { text-align:left; padding:12px 16px; border-bottom:1px solid var(--line); vertical-align:top; font-size:.95rem; }
        th { background:var(--brand-soft); }
        tr:last-child td { border-bottom:0; }
        .badge { display:inline-block; padding:2px 10px; border-radius:999px; font-size:.82rem; font-weight:600; background:var(--brand-soft); color:var(--brand); white-space:nowrap; }
        .badge.planned { background:var(--warn-soft); color:var(--warn); }
        .note { background:var(--brand-soft); border:1px solid var(--line); border-radius:12px; padding:14px 18px; margin-top:14px; }
        footer { padding:30px 0 44px; color:var(--muted); font-size:.88rem; border-top:1px solid var(--line); }
        @media (max-width:760px) { .grid { grid-template-columns:1fr; } .branches { grid-template-columns:1fr; } .flow .arrow { transform:rotate(90deg); width:100%; text-align:center; } }
        @media print {
            :root { --bg:#fff; --fg:#000; --muted:#333; --card:#fff; --line:#999; --brand:#000; --brand-soft:#fff; --warn:#000; --warn-soft:#fff; }
            body { font-size:11pt; }
            header.top, footer { display:none; }
            section { break-inside:avoid; }
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
        <h1>Trasparenza e conformità</h1>
        <p class="meta">Ultimo aggiornamento: {{ config('privacy.updated_at') }}</p>
        <p class="lead">Questa pagina descrive come il servizio tratta i dati, chi fa cosa e quali misure sono attive. È rivolta alle aziende che lo usano; l'informativa per i clienti finali è <a href="/privacy">qui</a>.</p>

        <section>
            <h2><span class="n">01</span>Flusso dei dati</h2>
            <div class="flow">
                <div class="step"><b>Agente</b>Scrive dal proprio WhatsApp al numero del servizio.</div>
                <div class="arrow">→</div>
                <div class="step"><b>WhatsApp Business Platform</b>Trasporta i messaggi (Meta Platforms).</div>
                <div class="arrow">→</div>
                <div class="step"><b>Servizio</b>Guida le domande e controlla i dati mentre vengono scritti.</div>
                <div class="arrow">→</div>
                <div class="branches">
                    <div class="step"><b>Database cifrato</b>Dati personali cifrati a riposo.</div>
                    <div class="step"><b>Archivio privato</b>Documenti non raggiungibili da internet.</div>
                    <div class="step {{ $aiActive ? '' : 'planned' }}"><b>Anthropic (Claude API){{ $aiActive ? '' : ' · previsto' }}</b>Lettura automatica dei documenti, con controllo dell'agente e dell'istruttore.</div>
                    <div class="step"><b>Pannello riservato</b>Consultazione da parte del personale autorizzato.</div>
                </div>
            </div>
            <p class="meta">Le copie di backup sono gestite dal Responsabile del trattamento, senza accesso ai dati.</p>
        </section>

        <section>
            <h2><span class="n">02</span>Ruoli</h2>
            <div class="grid">
                <div class="card">
                    <h3>Titolare del trattamento</h3>
                    <p><strong>{{ $ph($company?->name) }}</strong><br>L'azienda per cui l'agente opera: decide perché e come trattare i dati.</p>
                </div>
                <div class="card">
                    <h3>Responsabile del trattamento</h3>
                    <p><strong>{{ $responsabile }}</strong><br>Nominata dal Titolare (art. 28 GDPR). Si occupa solo del backup e non ha accesso ai dati.</p>
                </div>
                <div class="card">
                    <h3>Sub-responsabili</h3>
                    <p>Fornitori tecnici che trattano dati per conto del Titolare. Sono elencati al punto 05.</p>
                </div>
            </div>
        </section>

        <section>
            <h2><span class="n">03</span>Protezione dei dati</h2>
            <ul>
                <li><strong>Minimizzazione:</strong> la richiesta iniziale è anonima. Nome, codice fiscale, recapiti e dati dell'azienda non vengono chiesti finché il cliente non ha firmato l'informativa.</li>
                <li><strong>Informativa prima dei dati:</strong> i dati personali si raccolgono solo dopo la ricezione dell'informativa firmata, conservata con data e ora.</li>
                <li><strong>Conservazione:</strong> le pratiche non perfezionate sono cancellate automaticamente, con i loro file, dopo {{ $giorni }} giorni dalla richiesta.</li>
                <li><strong>Cancellazione:</strong> eliminando una pratica dal pannello si eliminano anche i documenti collegati.</li>
                <li><strong>Diritti degli interessati:</strong> accesso, rettifica, cancellazione, limitazione, portabilità e opposizione si esercitano presso il Titolare, che dispone degli strumenti per darvi seguito.</li>
                <li><strong>Uso dei dati:</strong> i dati servono solo a istruire la pratica. Non sono usati per pubblicità né ceduti a terzi per finalità proprie. @if ($aiActive)La lettura automatica dei documenti con intelligenza artificiale è attiva: il fornitore AI è tenuto per contratto a non usare i dati per addestrare i modelli.@else Le funzioni di intelligenza artificiale non sono ancora attive e nessun dato è usato per addestrare modelli; ai fornitori AI sarà richiesto per contratto di non usarli a questo scopo.@endif</li>
            </ul>
        </section>

        <section>
            <h2><span class="n">04</span>Intelligenza artificiale e decisioni</h2>
            <p>L'intelligenza artificiale {{ $aiActive ? 'è usata' : 'è prevista' }} soltanto per leggere i documenti inviati (ad esempio documento d'identità o certificati), ricavarne i dati e confrontarli con quelli dichiarati. Ogni dato estratto viene mostrato all'agente, che lo conferma o lo corregge.</p>
            <ul>
                <li>Il servizio non valuta il merito creditizio, non calcola punteggi e non decide sull'esito del finanziamento.</li>
                <li>L'esito è sempre dato da un istruttore, agente abilitato OAM: la decisione resta sempre di una persona.</li>
                <li>Per questo il servizio non è impiegato come sistema di intelligenza artificiale per valutare l'affidabilità creditizia ai sensi del Regolamento (UE) 2024/1689.</li>
            </ul>
        </section>

        <section>
            <h2><span class="n">05</span>Sub-responsabili</h2>
            <table>
                <thead><tr><th>Fornitore</th><th>Ruolo</th><th>Sede e garanzie</th><th>Stato</th></tr></thead>
                <tbody>
                @foreach ($subprocessors as $sub)
                    <tr>
                        <td><strong>{{ $sub['name'] }}</strong></td>
                        <td>{{ $sub['role'] }}</td>
                        <td>{{ $sub['location'] }}</td>
                        <td><span class="badge {{ ($sub['status'] ?? '') === 'previsto' ? 'planned' : '' }}">{{ $statusLabel[$sub['status'] ?? ''] ?? $sub['status'] }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <p class="meta" style="margin-top:10px">Un nuovo fornitore viene aggiunto a questo elenco prima di cominciare a trattare dati.</p>
        </section>

        <section>
            <h2><span class="n">06</span>Misure di sicurezza</h2>
            <ul>
                <li>Dati e documenti su server sicuri, in forma cifrata.</li>
                <li>Dati personali cifrati nel database; documenti in un archivio privato, non raggiungibile da internet.</li>
                <li>Accesso al pannello con account personali; nessuna registrazione pubblica.</li>
                <li>Controllo dei dati mentre vengono inseriti (codice fiscale, date, maggiore età, IBAN) per ridurre gli errori.</li>
                <li>I registri tecnici non contengono dati personali dei clienti.</li>
                <li>Cancellazione automatica delle pratiche non perfezionate e dei relativi documenti.</li>
                <li>Copie di backup gestite dal Responsabile, senza accesso ai dati.</li>
            </ul>
        </section>

        <section>
            <h2><span class="n">07</span>Certificazioni</h2>
            <table>
                <thead><tr><th>Certificazione</th><th>Stato</th></tr></thead>
                <tbody>
                @foreach ($certifications as $cert)
                    <tr>
                        <td><strong>{{ $cert['name'] }}</strong></td>
                        <td><span class="badge {{ ($cert['status'] ?? '') === 'ottenuta' ? '' : 'planned' }}">{{ $statusLabel[$cert['status'] ?? ''] ?? $cert['status'] }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <p class="meta" style="margin-top:10px">Una certificazione compare come ottenuta solo dopo il rilascio.</p>
        </section>

        <section>
            <h2><span class="n">08</span>Settore del credito</h2>
            <p>Il servizio raccoglie, controlla e ordina le informazioni di una pratica e le trasmette all'istruttoria del mediatore creditizio. Non sostituisce gli obblighi informativi e contrattuali del mediatore: i testi dell'informativa e dei consensi restano quelli del Titolare.</p>
        </section>

        <section>
            <h2><span class="n">09</span>Documenti</h2>
            <ul>
                <li><a href="/privacy">Informativa privacy</a> per i clienti finali, in versione stampabile con riquadro di presa visione.</li>
                <li><a href="/cancellazione-dati">Istruzioni per la cancellazione dei dati</a>: come un interessato chiede di eliminare i propri dati.</li>
                <li>Atto di nomina a Responsabile del trattamento (art. 28 GDPR): disponibile su richiesta a {{ $ph($company?->email) }}.</li>
            </ul>
        </section>

        <section>
            <h2><span class="n">10</span>Stato della trasparenza</h2>
            <div class="grid" style="grid-template-columns:1fr 1fr">
                <div class="card">
                    <h3>Già attivo</h3>
                    <p>Richiesta anonima · informativa prima dei dati personali · cancellazione automatica · dati cifrati · decisione sempre affidata a un istruttore@if ($aiActive) · lettura automatica dei documenti con controllo umano@endif.</p>
                </div>
                <div class="card">
                    <h3>In arrivo</h3>
                    <p>@if ($aiActive)Certificazioni previste · aggiornamenti di questa pagina a ogni novità.@else Lettura dei documenti con intelligenza artificiale (con conferma dell'agente) · certificazioni previste · aggiornamento di questa pagina all'attivazione.@endif</p>
                </div>
            </div>
        </section>
    </main>

    <footer>
        <a href="/">← Torna alla home</a> · <a href="/privacy">Informativa privacy</a>
    </footer>
</div>
</body>
</html>
