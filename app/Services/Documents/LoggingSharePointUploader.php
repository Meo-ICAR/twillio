<?php

namespace App\Services\Documents;

use Illuminate\Support\Facades\Log;

/** Simulazione in attesa delle routine SharePoint: scrive nel log cartella, nome e dimensione, mai il contenuto. */
class LoggingSharePointUploader implements SharePointUploader
{
    public function upload(string $folder, string $filename, string $contents): void
    {
        Log::info('SharePoint (simulato): file archiviato', ['folder' => $folder, 'file' => $filename, 'bytes' => strlen($contents)]);
    }
}
