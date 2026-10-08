<?php

namespace App\Services\Documents;

/** Un lettore di documenti che sa dire quanto ha consumato l'ultima volta che ha letto. */
interface ReportsUsage
{
    /** @return array{model: string, input_tokens: int, output_tokens: int}|null null se l'ultima lettura non ha prodotto un consumo */
    public function lastUsage(): ?array;
}
