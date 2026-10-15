<?php

return [
    // Installazione dimostrativa: al primo messaggio il bot avvisa e fa scegliere tra finanziamento e fornitura (la company, quindi i percorsi).
    'enabled' => (bool) env('APP_DEMO', false),
    'notice' => "⚠️ *Installazione DEMO*\nI dati e le simulazioni sono di prova: nessuna pratica o contratto è reale.",
];
