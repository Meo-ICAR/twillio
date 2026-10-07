<?php

namespace App\Services\Crm;

use App\Mail\QuoteMail;
use App\Models\LoanRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/** Invia per email all'istruttoria i dati del preventivo, quando non c'è un preventivatore da chiamare. */
class QuoteEmailSender
{
    public function __construct(private LoanEmailSender $loans) {}

    public function send(LoanRequest $loan): bool
    {
        $to = $this->loans->recipient();
        if (! $to) {
            Log::warning('Invio preventivo via email: manca la casella dell\'istruttoria', ['loan' => $loan->code]);

            return false;
        }

        try {
            Mail::to($to)->send(new QuoteMail($loan));
        } catch (\Throwable $e) {
            Log::error('Invio preventivo via email non riuscito', ['loan' => $loan->code, 'exception' => $e::class]);

            return false;
        }

        return true;
    }
}
