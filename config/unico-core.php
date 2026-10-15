<?php

// Solo le chiavi che unicoagent cambia: il resto viene dai valori predefiniti del pacchetto (mergeConfigFrom).
return [
    // Gli utenti di unicoagent sono locali e non stanno nella tabella `users` del core: i loro id non vanno scritti in created_by/updated_by dei documenti.
    'audit' => [
        'stamp_users' => env('UNICO_CORE_STAMP_USERS', false),
    ],
];
