<?php

namespace Tests\Feature\Core;

use App\Mail\SignedDocumentsMail;
use App\Models\Company;
use App\Models\LoanRequest;
use App\Services\Conversation\ConversationEngine;
use App\Services\Conversation\IncomingMessage;
use App\Services\Documents\DocumentProductionException;
use App\Services\Documents\LoanDocumentProducer;
use App\Services\Documents\LoanTaskResolver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Unico\Core\Models\Document;
use Unico\Core\Models\DocumentRequirement;
use Unico\Core\Models\Task;

class ReviewFixesDocumentiTest extends CoreTestCase
{
    private function companyWithModules(int $docs = 1): Company
    {
        $coreId = $this->coreCompany()->id;
        $company = Company::create(['name' => 'GBD', 'produce_documents' => true, 'core_company_id' => $coreId, 'core_tipo_prodotto' => 'Utenza', 'istruttoria_email' => 'gbd@example.com']);
        $task = Task::create(['name' => 'Utenza · ALLA FIRMA '.uniqid(), 'taskable' => 'pratica', 'trigger_field' => 'tipo_prodotto', 'trigger_state' => 'equals', 'trigger_value' => 'Utenza',
            'trigger_subfield' => 'stato_pratica', 'trigger_subvalue' => 'ALLA FIRMA', 'company_id' => $coreId, 'is_active' => true]);
        foreach (range(1, $docs) as $i) {
            DocumentRequirement::create(['task_id' => $task->id, 'document_type_id' => $this->coreModule(null, "Modulo $i")->document_type_id, 'is_required' => true, 'sort_order' => $i]);
        }

        return $company;
    }

    // 1. Le pratiche trasmesse o da perfezionare non si cancellano e non risultano «in scadenza».
    public function test_la_pulizia_non_cancella_le_pratiche_trasmesse_o_da_perfezionare(): void
    {
        config(['privacy.retention_days' => 30]);
        $old = now()->subDays(60);
        foreach (['richiesta', 'perfezionata', 'invio_istruttoria', 'perfezionare'] as $status) {
            $loan = $this->makeLoan(['status' => $status]);
            $loan->forceFill(['created_at' => $old])->save();
        }

        Artisan::call('finanziamento:purge');

        $this->assertSame(['invio_istruttoria', 'perfezionare', 'perfezionata'], LoanRequest::orderBy('status')->pluck('status')->all());
    }

    // 2. Una pratica già trasmessa non si può perfezionare di nuovo.
    public function test_una_pratica_trasmessa_o_da_perfezionare_non_si_riperfeziona(): void
    {
        Mail::fake();
        foreach (['invio_istruttoria', 'perfezionare'] as $status) {
            $loan = $this->makeLoan(['status' => $status]);

            $replies = app(ConversationEngine::class)->handle(new IncomingMessage('393927968199', 'interactive', 'perfeziona:'.$loan->code, 'perfeziona:'.$loan->code));

            $this->assertStringContainsString('già stata perfezionata', implode(' ', array_map(fn ($r) => $r->body, $replies)), $status);
        }
        Mail::assertNothingSent();
    }

    // 3. Qualunque errore di produzione diventa un errore di firma chiaro; con firme già aperte lo stato torna «in attesa».
    public function test_un_errore_imprevisto_del_core_diventa_errore_di_produzione(): void
    {
        $company = $this->companyWithModules();
        $loan = $this->makeLoan();
        $this->app->instance(LoanTaskResolver::class, new class extends LoanTaskResolver
        {
            public function modules(Company $company, string $stato, ?\App\Models\LoanRequest $loan = null): \Illuminate\Support\Collection
            {
                throw new \RuntimeException('core giù');
            }
        });

        try {
            app(LoanDocumentProducer::class)->produce($loan, $company, 'ALLA FIRMA');
            $this->fail('Doveva fallire');
        } catch (DocumentProductionException $e) {
            $this->assertStringNotContainsString('core giù', $e->getMessage());
        }

        $this->assertSame('errore', $loan->fresh()->signature_status);
    }

    public function test_con_una_firma_gia_aperta_il_reinvio_riporta_lo_stato_in_attesa(): void
    {
        $company = $this->companyWithModules();
        $loan = $this->makeLoan();
        app(LoanDocumentProducer::class)->produce($loan, $company, 'ALLA FIRMA');
        $loan->update(['signature_status' => null]);

        $result = app(LoanDocumentProducer::class)->produce($loan->fresh(), $company, 'ALLA FIRMA');

        $this->assertSame(['sent' => 0, 'skipped' => 1], $result);
        $this->assertSame('in_attesa', $loan->fresh()->signature_status);
    }

    // 4. Se due firme si completano insieme e nessun listener vede l'altra, il recupero periodico chiude la pratica una volta sola.
    public function test_il_comando_di_recupero_chiude_le_pratiche_con_tutte_le_firme_complete(): void
    {
        Mail::fake();
        Queue::fake();
        $company = $this->companyWithModules(2);
        $loan = $this->makeLoan(['status' => 'invio_istruttoria']);
        app(LoanDocumentProducer::class)->produce($loan, $company, 'ALLA FIRMA');
        Document::where('documentable_id', $loan->id)->update(['is_signed' => true, 'signed_at' => now()]);

        Artisan::call('signatures:finalize');
        Artisan::call('signatures:finalize');

        $loan->refresh();
        $this->assertSame('perfezionare', $loan->status);
        $this->assertSame('firmata', $loan->signature_status);
        Mail::assertSent(SignedDocumentsMail::class, 1);
        Queue::assertPushed(\App\Jobs\SendBpmEvent::class, 1);
    }

    public function test_il_recupero_non_tocca_le_pratiche_con_firme_mancanti(): void
    {
        Mail::fake();
        $company = $this->companyWithModules(2);
        $loan = $this->makeLoan(['status' => 'invio_istruttoria']);
        app(LoanDocumentProducer::class)->produce($loan, $company, 'ALLA FIRMA');
        Document::where('documentable_id', $loan->id)->limit(1)->update(['is_signed' => true]);

        Artisan::call('signatures:finalize');

        $this->assertSame('invio_istruttoria', $loan->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_il_recupero_e_la_sincronizzazione_delle_firme_sono_pianificati(): void
    {
        Artisan::call('schedule:list');
        $out = Artisan::output();

        $this->assertStringContainsString('signatures:finalize', $out);
        $this->assertStringContainsString('signature:sync', $out);
    }

    // 6. Company con la produzione attiva ma senza task/moduli per lo stato: si comporta come prima (perfezionata, evento BPM).
    // (copre anche test_senza_task_per_lo_stato… di PerfezionamentoConDocumentiTest, da aggiornare)

    // Reinvia compare anche se la firma resta in attesa oltre la scadenza (il core non l'ha ancora chiusa).
    public function test_reinvia_compare_per_una_firma_in_attesa_da_troppo_tempo(): void
    {
        Company::create(['name' => 'GBD', 'produce_documents' => true, 'core_company_id' => $this->coreCompany()->id, 'core_tipo_prodotto' => 'Utenza']);
        $loan = $this->makeLoan(['signature_status' => 'in_attesa', 'documents_sent_at' => now()->subDays(9)]);
        $this->actingAs(\App\Models\User::factory()->create());

        \Livewire\Livewire::test(\App\Filament\Resources\LoanRequests\Pages\ViewLoanRequest::class, ['record' => $loan->getKey()])->assertActionVisible('reinviaFirma');
    }
}
