<?php

namespace App\Services\Documents;

use App\Models\Attachment;

/** Esito dell'analisi di un documento. */
final class AnalysisOutcome
{
    /**
     * @param  string  $status  verificato | difforme | non_leggibile | non_analizzato
     * @param  list<string>  $issues
     * @param  array<string,string>  $proposals  dati letti, da far confermare (solo se il documento è a posto)
     */
    public function __construct(
        public readonly Attachment $attachment,
        public readonly string $status,
        public readonly string $label,
        public readonly string $aiKind,
        public readonly array $issues = [],
        public readonly array $proposals = [],
    ) {}

    public function passed(): bool
    {
        return $this->status === 'verificato';
    }
}
