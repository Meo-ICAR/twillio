<?php

namespace App\Services\Checks;

/** Ricava dal documento i dati che il dialogo chiederebbe, da proporre all'agente per la conferma. */
class EstraiDatiCheck implements DocumentCheck
{
    /** Campi letti dall'AI => nome del dato nel dialogo, per ogni documento. */
    private const COMMON = ['surname' => 'cognome', 'name' => 'nome', 'fiscal_code' => 'codice_fiscale'];

    /** Solo dal documento d'identità: sulla tessera il «numero» è un'altra cosa. */
    private const IDENTITY_ONLY = ['document_number' => 'documento_numero', 'expiry_date' => 'documento_scadenza'];

    public function label(): string
    {
        return 'Ricava i dati';
    }

    public function description(): string
    {
        return 'Legge dal documento cognome, nome, codice fiscale (e numero e scadenza se è un documento d\'identità) e li propone all\'agente per la conferma.';
    }

    public function inspect(DocumentContext $ctx): DocumentCheckResult
    {
        $fields = $ctx->fields() ?? [];
        $map = self::COMMON + ($ctx->readerKind === 'documento_identita' ? self::IDENTITY_ONLY : []);

        $proposals = [];
        foreach ($map as $from => $to) {
            $value = trim((string) ($fields[$from] ?? ''));
            if ($value === '') {
                continue;
            }
            $proposals[$to] = $to === 'codice_fiscale' ? strtoupper(preg_replace('/\s+/', '', $value)) : $value;
        }

        return DocumentCheckResult::ok($proposals);
    }
}
