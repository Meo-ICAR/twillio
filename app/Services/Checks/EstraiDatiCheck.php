<?php

namespace App\Services\Checks;

/** Ricava dal documento i dati che il dialogo chiederebbe, da proporre all'agente per la conferma. */
class EstraiDatiCheck implements DocumentCheck
{
    /** Campi letti dall'AI => nome del dato nel dialogo, per ogni documento. */
    private const COMMON = ['surname' => 'cognome', 'name' => 'nome', 'fiscal_code' => 'codice_fiscale'];

    /** Solo dal documento d'identità: sulla tessera il «numero» è un'altra cosa. */
    private const IDENTITY_ONLY = ['document_number' => 'documento_numero', 'expiry_date' => 'documento_scadenza'];

    /** Tipo di documento letto dall'AI => codice della risposta nel dialogo. */
    private const DOCUMENT_TYPES = ['carta_identita' => 'ci', 'patente' => 'patente', 'passaporto' => 'passaporto'];

    public function label(): string
    {
        return 'Ricava i dati';
    }

    public function description(): string
    {
        return 'Legge dal documento cognome, nome, codice fiscale (e tipo, numero e scadenza se è un documento d\'identità) e li propone all\'agente per la conferma.';
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

        // Dal documento d'identità si capisce anche di che tipo è: la domanda sul tipo non serve più.
        $type = self::DOCUMENT_TYPES[$fields['document_type'] ?? ''] ?? null;
        if ($type && $ctx->readerKind === 'documento_identita') {
            $proposals['documento_tipo'] = $type;
        }

        return DocumentCheckResult::ok($proposals);
    }
}
