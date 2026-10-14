<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\LoanRequest;
use App\Services\Crm\Capabilities\SendsDocuments;
use App\Services\Crm\CrmRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * Consegna al CRM i documenti di una pratica perfezionata, se il CRM dell'azienda li accetta (capacità SendsDocuments).
 * Se qualcosa non va l'errore fa ritentare il job con attesa crescente: i file già consegnati non vengono rimandati.
 */
class SendDocumentsToCrm implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public int $loanId) {}

    public function handle(CrmRegistry $registry): void
    {
        $loan = LoanRequest::find($this->loanId);

        if (! $loan || $loan->is_test || $loan->perfected_at === null) {
            return;
        }

        $company = Company::forWhatsApp($loan->agent_wa_number);
        $driver = $company?->crmDriver();

        if ($company === null || $driver === null || ! $registry->supports($driver, SendsDocuments::class)) {
            return;
        }

        $status = $registry->make($driver, $company)->sendDocuments($loan);

        if ($status !== 200) {
            // Nessun dato personale nel messaggio: solo il codice.
            throw new RuntimeException("Consegna dei documenti al CRM non riuscita (codice {$status}).");
        }
    }
}
