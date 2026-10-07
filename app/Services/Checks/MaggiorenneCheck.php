<?php

namespace App\Services\Checks;

use Illuminate\Support\Carbon;

/**
 * Controlla l'età minima su una data di nascita.
 * Parametri: `anni` (default 18), `campo` (dato del dialogo che contiene la data; se manca si usa la risposta stessa),
 * `messaggio` (testo d'errore). Se non trova una data da controllare non blocca: ci pensano gli altri controlli.
 */
class MaggiorenneCheck implements NodeCheck
{
    public function label(): string
    {
        return 'Età minima';
    }

    public function description(): string
    {
        return 'Verifica che la data di nascita (della risposta o di un dato già ricavato) corrisponda almeno all\'età indicata, di norma 18 anni.';
    }

    public function derives(): array
    {
        return [];
    }

    public function passes(string $value, CheckContext $ctx): bool
    {
        $field = $ctx->param('campo');
        $source = $field ? $ctx->get($field) : $value;

        if (! is_string($source) || ! preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', trim($source), $m) || ! checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return true;
        }

        $years = (int) $ctx->param('anni', 18);
        $birth = Carbon::createFromDate((int) $m[3], (int) $m[2], (int) $m[1])->startOfDay();

        return $birth->gt(today()->subYears($years))
            ? $ctx->fail($ctx->param('messaggio', "Il cliente deve avere almeno {$years} anni."))
            : true;
    }
}
