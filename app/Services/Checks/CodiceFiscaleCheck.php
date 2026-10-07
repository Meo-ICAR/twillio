<?php

namespace App\Services\Checks;

use App\Services\Conversation\CodiceFiscale;

class CodiceFiscaleCheck implements NodeCheck
{
    public function label(): string
    {
        return 'Codice fiscale';
    }

    public function description(): string
    {
        return 'Verifica che il codice fiscale sia interpretabile e ne ricava data e luogo di nascita e sesso.';
    }

    public function derives(): array
    {
        return ['data_nascita', 'sesso', 'luogo_nascita'];
    }

    public function passes(string $value, CheckContext $ctx): bool
    {
        $info = CodiceFiscale::parse($value);
        if (! $info) {
            return false;
        }

        $ctx->set('data_nascita', $info['birth_date']);
        $ctx->set('sesso', $info['sex']);
        if ($info['place']) {
            $ctx->set('luogo_nascita', $info['place']);
        }

        return true;
    }
}
