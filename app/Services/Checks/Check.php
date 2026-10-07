<?php

namespace App\Services\Checks;

/** Un controllo registrato per nome: su una risposta (NodeCheck) o su un documento caricato (DocumentCheck). */
interface Check
{
    /** Nome mostrato nel pannello. */
    public function label(): string;

    /** Cosa controlla, in una frase, mostrata nel pannello. */
    public function description(): string;
}
