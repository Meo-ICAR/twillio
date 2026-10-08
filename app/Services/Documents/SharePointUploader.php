<?php

namespace App\Services\Documents;

/** Scrive un file su SharePoint. L'implementazione reale arriva dopo: oggi c'è il caricatore finto. */
interface SharePointUploader
{
    /** @param  string  $folder  cartella (il codice pratica) */
    public function upload(string $folder, string $filename, string $contents): void;
}
