<?php

namespace App\Services\Checks;

use Unico\Core\Signature\PhoneNumber;

/** Controlla che sia un cellulare (italiano, o estero in forma internazionale): serve per l'OTP via SMS. */
class MobileCheck implements NodeCheck
{
    public function label(): string
    {
        return 'Cellulare';
    }

    public function description(): string
    {
        return 'Verifica che il numero sia un cellulare valido (3xx per l\'Italia, forma internazionale per l\'estero), non un fisso.';
    }

    public function derives(): array
    {
        return [];
    }

    public function passes(string $value, CheckContext $ctx): bool
    {
        $message = PhoneNumber::check($value, mobileOnly: true, allowForeign: true);

        return $message === null ? true : $ctx->fail($message.' Riprova.');
    }
}
