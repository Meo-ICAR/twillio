<?php

namespace App\Services\Checks;

use App\Services\Documents\DocumentChecker;

class DatiCoerentiCheck implements DocumentCheck
{
    public function label(): string
    {
        return 'Dati coerenti';
    }

    public function description(): string
    {
        return 'Confronta i dati letti dal documento con quelli già noti sulla pratica (cognome, nome, codice fiscale, numero e scadenza) e segnala il documento scaduto.';
    }

    public function inspect(DocumentContext $ctx): DocumentCheckResult
    {
        $issues = app(DocumentChecker::class)->matchIssues($ctx->readerKind, $ctx->fields() ?? [], $ctx->declared());

        return $issues === [] ? DocumentCheckResult::ok() : DocumentCheckResult::fail($issues);
    }
}
