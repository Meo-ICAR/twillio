<?php

namespace App\Services\Checks;

use Unico\Core\Support\EmailAddress;

/** Controlla l'indirizzo email con la routine di unico-core e propone la correzione dei refusi di dominio (gmial.com). */
class EmailCheck implements NodeCheck
{
    public function label(): string
    {
        return 'Email';
    }

    public function description(): string
    {
        return 'Verifica che l\'indirizzo email sia scritto correttamente (chiocciola, dominio e suffisso) e segnala i refusi comuni.';
    }

    public function derives(): array
    {
        return [];
    }

    public function passes(string $value, CheckContext $ctx): bool
    {
        if (($message = EmailAddress::check($value)) !== null) {
            return $ctx->fail($message.' Riprova.');
        }

        if (($suggestion = EmailAddress::suggest($value)) !== null) {
            return $ctx->fail("Sembra un refuso: intendevi {$suggestion}? Riscrivi l'indirizzo giusto.");
        }

        return true;
    }
}
