<?php

namespace App\Services\Crm\Capabilities;

use App\Models\LoanRequest;

/** Il CRM accetta anche i documenti della pratica (oltre ai dati). */
interface SendsDocuments
{
    /** @return int codice HTTP: 200 = tutti i documenti consegnati */
    public function sendDocuments(LoanRequest $loan): int;
}
