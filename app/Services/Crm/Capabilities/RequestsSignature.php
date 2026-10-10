<?php

namespace App\Services\Crm\Capabilities;

use App\Models\LoanRequest;

/** Il CRM avvia la firma elettronica con OTP di un documento e ne riporta lo stato. */
interface RequestsSignature
{
    /** @return array{status: string, reference: ?string} stato: inviata | firmata | rifiutata | scaduta | errore */
    public function requestSignature(LoanRequest $loan, string $document): array;

    /** @return array{status: string, reference: ?string} */
    public function signatureStatus(LoanRequest $loan, string $reference): array;
}
