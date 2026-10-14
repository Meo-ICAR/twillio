<?php

namespace App\Services\Crm\Capabilities;

use App\Models\LoanRequest;

/** Il CRM compila un modulo (PDF) con i dati della pratica, pronto da stampare. */
interface FillsForms
{
    /** Contenuto binario del PDF compilato, o null se il modulo non è disponibile. */
    public function fillForm(LoanRequest $loan, string $template): ?string;
}
