<?php

namespace App\Services\Conversation;

use App\Models\LoanRequest;
use Illuminate\Support\Facades\DB;

class LoanRequestCode
{
    /** Codice progressivo dell'anno. Le pratiche di prova hanno il prefisso TST e una numerazione a parte. */
    public static function next(bool $test = false): string
    {
        return DB::transaction(function () use ($test) {
            $year = now()->year;
            $prefix = $test ? 'TST' : 'FIN';
            $last = LoanRequest::where('code', 'like', "{$prefix}-{$year}-%")
                ->lockForUpdate()
                ->orderByDesc('code')
                ->value('code');

            return sprintf('%s-%d-%04d', $prefix, $year, $last ? (int) substr($last, -4) + 1 : 1);
        });
    }
}
