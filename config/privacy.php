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

    // Certificazioni: 'status' = prevista | in_corso | ottenuta. Mettere "ottenuta" solo a certificazione rilasciata.
    'certifications' => [
        ['name' => 'CSA STAR', 'status' => 'prevista'],
    ],

    // Fornitori che trattano dati per conto del Titolare (oltre al Responsabile). 'status' = attivo | previsto.
    'subprocessors' => [
        [
            'name' => 'Meta Platforms (WhatsApp Business Platform)',
            'role' => 'Trasporto dei messaggi scambiati con il servizio',
            'location' => 'Secondo le condizioni del fornitore; possibili trasferimenti extra SEE con le garanzie da esso adottate',
            'status' => 'attivo',
        ],
        [
            'name' => 'Fornitore di intelligenza artificiale [da completare]',
            'role' => 'Estrazione dei dati dai documenti inviati, sempre con conferma dell\'agente',
            'location' => '[da completare]',
            'status' => 'previsto',
        ],
    ],

    'updated_at' => '7 ottobre 2026',
];
