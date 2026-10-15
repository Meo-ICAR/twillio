<?php

namespace App\Services\Checks;

use Illuminate\Support\Facades\Log;
use Unico\Core\Clients\OpenApiCompanyService;

/** Interroga il Registro Imprese (Openapi.it, tramite unico-core) per ricavare ragione sociale e sede da una partita IVA. Se non risponde si prosegue: i dati si chiedono a mano. */
class RegistroImpreseCheck implements NodeCheck
{
    public function label(): string
    {
        return 'Registro Imprese';
    }

    public function description(): string
    {
        return 'Dalla partita IVA ricava ragione sociale e sede legale dal Registro Imprese; se il servizio non risponde non blocca il dialogo.';
    }

    public function derives(): array
    {
        return ['ragione_sociale', 'residenza'];
    }

    public function passes(string $value, CheckContext $ctx): bool
    {
        $vat = strtoupper(preg_replace('/\s+/', '', $value) ?? '');
        if (! partitaIvaValida($vat)) {
            return false;
        }

        try {
            $company = app(OpenApiCompanyService::class)->fetchCompany($vat)['company'];
        } catch (\Throwable $e) {
            // Solo il tipo di errore: il messaggio potrebbe contenere dati dell'azienda.
            Log::warning('Registro Imprese non disponibile', ['exception' => $e::class]);

            return true;
        }

        if (filled($company['name'] ?? null)) {
            $ctx->set('ragione_sociale', (string) $company['name']);
        }
        if (filled($company['address'] ?? null)) {
            $ctx->set('residenza', (string) $company['address']);
        }

        return true;
    }
}
