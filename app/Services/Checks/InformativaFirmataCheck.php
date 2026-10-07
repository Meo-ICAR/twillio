<?php

namespace App\Services\Checks;

use App\Services\Documents\DocumentChecker;

/** L'informativa è un nostro modulo: l'AI controlla solo che l'allegato sia quel modulo e che sia firmato. */
class InformativaFirmataCheck implements DocumentCheck
{
    public function label(): string
    {
        return 'Informativa firmata';
    }

    public function description(): string
    {
        return 'Verifica che l\'allegato sia il nostro modulo di informativa privacy e che sia firmato dal cliente.';
    }

    public function inspect(DocumentContext $ctx): DocumentCheckResult
    {
        $fields = $ctx->fields() ?? [];

        if ($issues = app(DocumentChecker::class)->readabilityIssues($fields)) {
            return DocumentCheckResult::fail($issues);
        }

        if (($fields['kind_detected'] ?? null) !== 'informativa' || ($fields['matches_template'] ?? null) !== true) {
            return DocumentCheckResult::fail(['Il file non sembra il nostro modulo di informativa privacy: scarica il modulo dal link, fallo firmare e invia di nuovo la foto o il PDF.']);
        }

        // Se la firma non si vede con certezza non si dà per firmata.
        if (($fields['signed'] ?? null) !== true) {
            return DocumentCheckResult::fail(['L\'informativa non risulta firmata: fai firmare il cliente nello spazio «Per presa visione» e invia di nuovo il modulo.']);
        }

        return DocumentCheckResult::ok();
    }
}
