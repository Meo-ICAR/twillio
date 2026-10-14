<?php

namespace App\Services\Checks;

use App\Services\Conversation\PartitaIva;

class PartitaIvaCheck implements NodeCheck
{
    public function label(): string
    {
        return 'Partita IVA';
    }

    public function description(): string
    {
        return 'Verifica formato e cifra di controllo della partita IVA.';
    }

    public function derives(): array
    {
        return [];
    }

    public function passes(string $value, CheckContext $ctx): bool
    {
        return PartitaIva::isValid($value);
    }
}
