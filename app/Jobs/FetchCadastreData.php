<?php

namespace App\Jobs;

use App\Models\LoanRequest;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Unico\Core\Clients\OpenApiCadastreService;

/** Recupera in background i dati catastali di un privato (codice fiscale + indirizzo). Se il servizio non risponde non si blocca nulla. */
class FetchCadastreData
{
    use Dispatchable;

    public function __construct(public readonly int $loanId) {}

    public function handle(OpenApiCadastreService $service): void
    {
        $loan = LoanRequest::find($this->loanId);
        $personal = $loan?->personal ?? [];
        if (! $loan || blank($personal['codice_fiscale'] ?? null) || blank($personal['residenza'] ?? null)) {
            return;
        }

        try {
            $loan->update(['cadastre' => $service->lookup($personal['codice_fiscale'], $personal['residenza'])]);
        } catch (\Throwable $e) {
            // Solo il tipo di errore: il messaggio potrebbe contenere dati personali.
            Log::warning('Catasto non disponibile', ['loan' => $loan->code, 'exception' => $e::class]);
        }
    }
}
