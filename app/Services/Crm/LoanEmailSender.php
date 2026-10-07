<?php

namespace App\Services\Crm;

use App\Mail\LoanSubmissionMail;
use App\Models\Company;
use App\Models\LoanRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/** Invia per email all'istruttoria i dati della pratica e i suoi allegati. */
class LoanEmailSender
{
    public function recipient(): ?string
    {
        return Company::current()?->istruttoria_email ?: config('finanziamento.mail.to');
    }

    /** @return bool true se la mail è partita */
    public function send(LoanRequest $loan): bool
    {
        $to = $this->recipient();
        if (! $to) {
            Log::warning('Invio pratica via email: manca la casella dell\'istruttoria', ['loan' => $loan->code]);

            return false;
        }

        try {
            Mail::to($to)->send(new LoanSubmissionMail($loan));
        } catch (\Throwable $e) {
            // Solo il tipo di errore: il messaggio potrebbe contenere dati personali.
            Log::error('Invio pratica via email non riuscito', ['loan' => $loan->code, 'exception' => $e::class]);

            return false;
        }

        $loan->update(['emailed_at' => now()]);

        return true;
    }
}
