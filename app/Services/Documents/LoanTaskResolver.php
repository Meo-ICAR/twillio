<?php

namespace App\Services\Documents;

use App\Models\Company;
use App\Models\LoanRequest;
use Illuminate\Support\Collection;
use Unico\Core\Models\DocumentRequirement;
use Unico\Core\Models\PdfModule;
use Unico\Core\Models\Task;

/** Dice quali moduli PDF produrre per una pratica: quelli dei documenti richiesti dai task del core per tipo di pratica e stato. */
class LoanTaskResolver
{
    /** @return Collection<int,PdfModule> */
    public function modules(Company $company, string $stato, ?LoanRequest $loan = null): Collection
    {
        if (! $company->producesDocuments()) {
            return collect();
        }

        $taskIds = Task::forCompany($company->core_company_id)
            ->where('is_active', true)
            ->where('trigger_field', 'tipo_prodotto')->where('trigger_value', $company->core_tipo_prodotto)
            ->where('trigger_subfield', 'stato_pratica')->where('trigger_subvalue', $stato)
            ->pluck('id');

        $typeIds = DocumentRequirement::whereIn('task_id', $taskIds)->orderBy('sort_order')->pluck('document_type_id')->unique()->values();
        if ($typeIds->isEmpty()) {
            return collect();
        }

        $modules = PdfModule::whereIn('document_type_id', $typeIds)->where('is_active', true)->get()->keyBy('document_type_id');

        // Pagamento con bollettino o carta: nessun modulo di addebito in conto (SDD).
        $skipSdd = ($loan?->personal['pagamento'] ?? null) === 'bollettino';

        return $typeIds->map(fn ($id) => $modules->get($id))->filter()
            ->reject(fn (PdfModule $m) => $skipSdd && str_contains($m->name, 'SDD'))->values();
    }
}
