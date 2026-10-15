<?php

namespace Tests\Feature\Core;

use App\Filament\Resources\LoanRequests\Pages\ViewLoanRequest;
use App\Filament\Resources\LoanRequests\RelationManagers\GeneratedDocumentsRelationManager;
use App\Models\Company;
use App\Models\User;
use App\Services\Documents\LoanDocumentProducer;
use Livewire\Livewire;
use Unico\Core\Models\Document;
use Unico\Core\Models\DocumentRequirement;
use Unico\Core\Models\Task;

class LoanRequestDocumentsPanelTest extends CoreTestCase
{
    /** @return array{0: \App\Models\LoanRequest, 1: \Illuminate\Support\Collection} */
    private function loanWithDocuments(int $docs = 2): array
    {
        $coreId = $this->coreCompany()->id;
        $company = Company::create(['name' => 'GBD', 'produce_documents' => true, 'core_company_id' => $coreId, 'core_tipo_prodotto' => 'Utenza']);
        $task = Task::create(['name' => 'Utenza · ALLA FIRMA '.uniqid(), 'taskable' => 'pratica', 'trigger_field' => 'tipo_prodotto', 'trigger_state' => 'equals', 'trigger_value' => 'Utenza',
            'trigger_subfield' => 'stato_pratica', 'trigger_subvalue' => 'ALLA FIRMA', 'company_id' => $coreId, 'is_active' => true]);
        foreach (range(1, $docs) as $i) {
            DocumentRequirement::create(['task_id' => $task->id, 'document_type_id' => $this->coreModule(null, "Modulo $i")->document_type_id, 'is_required' => true, 'sort_order' => $i]);
        }
        $loan = $this->makeLoan();
        app(LoanDocumentProducer::class)->produce($loan, $company, 'ALLA FIRMA');
        $this->actingAs(User::factory()->create());

        return [$loan, Document::where('documentable_type', 'loan_request')->where('documentable_id', $loan->id)->get()];
    }

    private function manager($loan)
    {
        return Livewire::test(GeneratedDocumentsRelationManager::class, ['ownerRecord' => $loan, 'pageClass' => ViewLoanRequest::class]);
    }

    public function test_la_pratica_elenca_tutti_i_documenti_prodotti_con_tipo_e_stato_di_firma(): void
    {
        [$loan, $docs] = $this->loanWithDocuments(2);

        $this->manager($loan)->assertCanSeeTableRecords($docs)->assertSee('Modulo 1')->assertSee('Modulo 2')->assertSee('Inviata al cliente');
    }

    public function test_un_documento_firmato_mostra_firmata_e_si_scarica_il_firmato(): void
    {
        [$loan, $docs] = $this->loanWithDocuments(1);
        $doc = $docs->first();
        $doc->addMediaFromString('%PDF-firmato')->usingFileName('firmato.pdf')->toMediaCollection(Document::COLLECTION_SIGNED);
        $doc->update(['is_signed' => true, 'signed_at' => now()]);

        $this->manager($loan)->assertSee('Firmata')->callTableAction('scarica', $doc)->assertFileDownloaded('firmato.pdf');
    }

    public function test_si_scarica_anche_l_originale_di_un_documento_firmato(): void
    {
        [$loan, $docs] = $this->loanWithDocuments(1);
        $doc = $docs->first();
        $original = $doc->getFirstMedia(Document::COLLECTION)->file_name;
        $doc->addMediaFromString('%PDF-firmato')->usingFileName('firmato.pdf')->toMediaCollection(Document::COLLECTION_SIGNED);
        $doc->update(['is_signed' => true]);

        $this->manager($loan)->callTableAction('scaricaOriginale', $doc)->assertFileDownloaded($original);
    }

    public function test_senza_documenti_prodotti_la_scheda_e_vuota(): void
    {
        $loan = $this->makeLoan();
        $this->actingAs(User::factory()->create());

        $this->manager($loan)->assertCountTableRecords(0);
    }

    public function test_non_si_vedono_i_documenti_di_un_altra_pratica(): void
    {
        [$loan, $docs] = $this->loanWithDocuments(1);
        $other = $this->makeLoan(['code' => 'ALTRA-1']);

        $this->manager($other)->assertCountTableRecords(0);
    }
}
