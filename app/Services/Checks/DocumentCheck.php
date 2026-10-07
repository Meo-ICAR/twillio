<?php

namespace App\Services\Checks;

/**
 * Un controllo su un documento caricato (foto o PDF). Si scrive come classe, si registra come gli altri controlli
 * e si aggancia a una domanda di tipo file dal pannello; se la domanda non ne ha, valgono quelli predefiniti
 * del tipo di documento (config 'document_checks').
 *
 * Non è istantaneo: gira dopo la risposta al webhook (vedi DocumentPipeline). Il controllo legge i campi del documento
 * da `$ctx->fields()`: la lettura con l'AI avviene una sola volta, qualunque sia il numero di controlli.
 * Se un controllo non passa gli altri non girano; i dati letti si propongono solo se passano tutti.
 */
interface DocumentCheck extends Check
{
    public function inspect(DocumentContext $ctx): DocumentCheckResult;
}
