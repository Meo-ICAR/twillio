<?php

// Dati mostrati nell'informativa privacy (/privacy) e termini di conservazione applicati dal sistema.
// Il Titolare del trattamento è l'azienda cliente: si inserisce dal pannello (Azienda), tabella companies.
// Se manca, la pagina mostra "[da completare]".

return [

    'responsabile' => [
        'nome' => 'Hassisto Srl',
    ],

    // Giorni dopo i quali una pratica non perfezionata viene cancellata (php artisan finanziamento:purge).
    'retention_days' => (int) env('PRIVACY_RETENTION_DAYS', 30),

    'updated_at' => '7 ottobre 2026',
];
