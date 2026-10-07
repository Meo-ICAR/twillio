<?php

namespace App\Services\Documents;

interface DocumentReader
{
    /** Vero se la lettura dei documenti con AI è configurata. */
    public function enabled(): bool;

    /**
     * Legge un documento (foto o PDF) e ne restituisce i campi, oppure null se non è stato possibile leggerlo.
     *
     * @return array<string,mixed>|null
     */
    public function read(string $kind, string $mime, string $bytes): ?array;
}
