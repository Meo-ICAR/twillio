<?php

namespace App\Services\Checks;

use App\Services\Documents\DocumentChecker;

class TipoDocumentoCheck implements DocumentCheck
{
    public function label(): string
    {
        return 'Tipo di documento';
    }

    public function description(): string
    {
        return 'Verifica che il file sia leggibile e sia davvero il documento richiesto (e non un altro).';
    }

    public function inspect(DocumentContext $ctx): DocumentCheckResult
    {
        $fields = $ctx->fields() ?? [];
        $checker = app(DocumentChecker::class);

        $issues = $checker->readabilityIssues($fields) ?: $checker->typeIssues($ctx->readerKind, $fields);

        return $issues === [] ? DocumentCheckResult::ok() : DocumentCheckResult::fail($issues);
    }
}
