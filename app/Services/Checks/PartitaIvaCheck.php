<?php

namespace App\Services\Checks;

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
        // Routine di unico-core (helpers): 11 cifre italiane con checksum o 13 caratteri UE; qui si ripulisce quanto scrive l'agente.
        return partitaIvaValida(strtoupper(preg_replace('/\s+/', '', $value)));
    }
}
