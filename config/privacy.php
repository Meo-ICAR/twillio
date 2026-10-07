<?php

// Dati mostrati nell'informativa privacy (/privacy) e termini di conservazione applicati dal sistema.
// I valori del Titolare si impostano nel file .env; se mancano la pagina mostra "[da completare]".

return [

    'titolare' => [
        'nome' => env('PRIVACY_TITOLARE_NOME'),
        'sede' => env('PRIVACY_TITOLARE_SEDE'),
        'email' => env('PRIVACY_TITOLARE_EMAIL'),
        'dpo' => env('PRIVACY_DPO_EMAIL'),
    ],

    'responsabile' => [
        'nome' => 'Hassisto Srl',
    ],

    // Giorni dopo i quali una pratica non perfezionata viene cancellata (php artisan finanziamento:purge).
    'retention_days' => (int) env('PRIVACY_RETENTION_DAYS', 30),

    // Testo libero sulla conservazione delle pratiche perfezionate (dipende dagli obblighi di legge del Titolare).
    'retention_perfected' => env('PRIVACY_RETENTION_PERFECTED'),

    'updated_at' => '7 ottobre 2026',
];
