<?php

namespace App\Services\Crm\Capabilities;

use App\Models\LoanRequest;

/** Il CRM mette a disposizione i template dei moduli per la pratica (scarico). */
interface ProvidesTemplates
{
    /** @return list<array{code: string, name: string}> */
    public function templates(LoanRequest $loan): array;

    /** Contenuto binario del template, o null se non disponibile. */
    public function downloadTemplate(LoanRequest $loan, string $code): ?string;
}
