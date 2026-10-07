<?php

namespace App\Services\Conversation;

use App\Models\LoanRequest;
use Illuminate\Support\Facades\DB;

class LoanRequestCode
{
    public static function next(): string
    {
        return DB::transaction(function () {
            $year = now()->year;
            $last = LoanRequest::where('code', 'like', "FIN-{$year}-%")
                ->lockForUpdate()
                ->orderByDesc('code')
                ->value('code');

            return sprintf('FIN-%d-%04d', $year, $last ? (int) substr($last, -4) + 1 : 1);
        });
    }
}
