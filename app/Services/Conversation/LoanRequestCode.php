<?php

namespace App\Services\Conversation;

use App\Models\LoanRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LoanRequestCode
{
    /**
     * Codice del preventivo: <sigla>-MMGG-HHmm, così dice anche quando è stato fatto (MM mese, GG giorno, ora e minuti).
     * Le pratiche di prova hanno il prefisso TST-. Se nello stesso minuto lo stesso produttore ne fa un altro si aggiunge una lettera.
     */
    public static function next(bool $test = false, string $sigla = 'SEG', ?Carbon $at = null): string
    {
        return DB::transaction(function () use ($test, $sigla, $at) {
            $base = ($test ? 'TST-' : '').strtoupper($sigla).'-'.($at ?? now())->format('md-Hi');

            $code = $base;
            foreach (range('A', 'Z') as $suffix) {
                if (! LoanRequest::where('code', $code)->lockForUpdate()->exists()) {
                    return $code;
                }
                $code = $base.$suffix;
            }

            throw new \RuntimeException('Troppi preventivi nello stesso minuto.');
        });
    }
}
