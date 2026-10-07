<?php

namespace App\Services\Checks;

/**
 * Un controllo sulla risposta a una domanda del dialogo.
 *
 * Si scrive come classe, si registra per nome in config/finanziamento.php ('checks') e si aggancia
 * a una domanda dal pannello (colonna `checks` di flow_nodes). Se `passes()` restituisce true il dialogo
 * prosegue; se restituisce false la domanda viene ripetuta con il messaggio del controllo
 * (`$ctx->fail('...')`) oppure, se non ne ha uno, con quello della domanda.
 */
interface NodeCheck extends Check
{
    /**
     * Chiavi dei dati che il controllo ricava con `$ctx->set()`.
     * Si azzerano quando la domanda viene rifatta o l'agente torna indietro.
     *
     * @return list<string>
     */
    public function derives(): array;

    /** True se la risposta va bene. Può ricavare dati (`$ctx->set()`) e dare il proprio messaggio d'errore (`$ctx->fail()`). */
    public function passes(string $value, CheckContext $ctx): bool;
}
