<?php

namespace App\Services\Crm;

use App\Models\LoanRequest;

/** Invia la pratica perfezionata al CRM del committente. */
interface CrmGateway
{
    /**
     * @param  array<string,mixed>  $personal  dati personali raccolti nel perfezionamento
     * @return int codice HTTP della risposta del CRM (200 = pratica accettata)
     */
    public function submit(LoanRequest $loan, array $personal): int;
}
