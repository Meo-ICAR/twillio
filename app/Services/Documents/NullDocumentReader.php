<?php

namespace App\Services\Documents;

/** Lettura dei documenti non configurata: i file vengono comunque ricevuti e archiviati. */
class NullDocumentReader implements DocumentReader
{
    public function enabled(): bool
    {
        return false;
    }

    public function read(string $kind, string $mime, string $bytes): ?array
    {
        return null;
    }
}
