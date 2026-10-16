<?php

namespace Tests\Feature\Core;

use App\Models\Company;
use App\Models\User;
use App\Services\Documents\DocumentProductionException;
use App\Services\Documents\LoanDocumentProducer;
use Illuminate\Support\Facades\DB;
use Unico\Core\Models\Document;
use Unico\Core\Models\DocumentRequirement;
use Unico\Core\Models\SignatureRequest;
use Unico\Core\Models\Task;

class LoanDocumentProducerTest extends CoreTestCase
{
    private int $coreId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->coreId = $this->coreCompany()->id;
    }

    private function company(): Company
    {
        return Company::create(['name' => 'GBD', 'produce_documents' => true, 'core_company_id' => $this->coreId, 'core_tipo_prodotto' => 'Utenza']);
    }

    private function withModules(int $count = 1): void
    {
        $task = Task::create(['name' => 'Utenza · ALLA FIRMA '.uniqid(), 'taskable' => 'pratica', 'trigger_field' => 'tipo_prodotto', 'trigger_state' => 'equals', 'trigger_value' => 'Utenza',
            'trigger_subfield' => 'stato_pratica', 'trigger_subvalue' => 'ALLA FIRMA', 'company_id' => $this->coreId, 'is_active' => true]);
        foreach (range(1, $count) as $i) {
            DocumentRequirement::create(['task_id' => $task->id, 'document_type_id' => $this->coreModule(null, "Modulo $i")->document_type_id, 'is_required' => true, 'sort_order' => $i]);
        }
    }

    public function test_produce_i_documenti_e_li_manda_in_firma(): void
    {
        $this->withModules(2);
        $loan = $this->makeLoan();

        $result = app(LoanDocumentProducer::class)->produce($loan, $this->company(), 'ALLA FIRMA');

        $this->assertSame(['sent' => 2, 'skipped' => 0], $result);
        $this->assertSame(2, Document::where('documentable_type', 'loan_request')->where('documentable_id', $loan->id)->count());
        $this->assertSame(2, SignatureRequest::whereIn('document_id', Document::where('documentable_type', 'loan_request')->where('documentable_id', $loan->id)->pluck('id'))->count());
        $loan->refresh();
        $this->assertSame('in_attesa', $loan->signature_status);
        $this->assertNotNull($loan->documents_sent_at);
        $doc = Document::where('documentable_type', 'loan_request')->where('documentable_id', $loan->id)->first();
        $this->assertSame('application/pdf', $doc->getFirstMedia(Document::COLLECTION)->mime_type);
        $this->assertSame('generato', $doc->channel);
    }

    public function test_il_reinvio_non_duplica_documenti_ne_richieste(): void
    {
        $this->withModules(1);
        $loan = $this->makeLoan();
        $producer = app(LoanDocumentProducer::class);

        $producer->produce($loan, $this->company(), 'ALLA FIRMA');
        $second = $producer->produce($loan->fresh(), $this->company(), 'ALLA FIRMA');

        $this->assertSame(['sent' => 0, 'skipped' => 1], $second);
        $this->assertSame(1, Document::where('documentable_type', 'loan_request')->where('documentable_id', $loan->id)->count());
        $this->assertSame(1, SignatureRequest::whereIn('document_id', Document::where('documentable_type', 'loan_request')->where('documentable_id', $loan->id)->pluck('id'))->count());
    }

    public function test_senza_email_o_cellulare_non_parte_nulla_e_lo_stato_e_errore(): void
    {
        $this->withModules(1);
        $loan = $this->makeLoan(['personal' => ['tipo_cliente' => 'privato', 'cognome' => 'Rossi', 'nome' => 'Mario', 'codice_fiscale' => 'RSSMRA80A01H501U', 'telefono' => '', 'email' => '']]);

        try {
            app(LoanDocumentProducer::class)->produce($loan, $this->company(), 'ALLA FIRMA');
            $this->fail('Doveva fallire');
        } catch (DocumentProductionException $e) {
            $this->assertStringContainsString('email', $e->getMessage());
        }

        $this->assertSame('errore', $loan->fresh()->signature_status);
        $this->assertSame(0, Document::where('documentable_type', 'loan_request')->where('documentable_id', $loan->id)->count());
    }

    public function test_un_indirizzo_o_un_numero_non_validi_non_fanno_partire_nulla_e_dicono_perche(): void
    {
        $this->withModules(1);
        $personal = ['tipo_cliente' => 'privato', 'cognome' => 'Rossi', 'nome' => 'Mario', 'codice_fiscale' => 'RSSMRA80A01H501U'];
        $cases = [
            ['email' => 'mario@example', 'telefono' => '3331234567', 'expect' => 'suffisso'],
            ['email' => 'mario@example.com', 'telefono' => '06 1234567', 'expect' => 'cellulare'],
            ['email' => 'mario@example.com', 'telefono' => '333abc4567', 'expect' => 'cifre'],
        ];

        foreach ($cases as $case) {
            $loan = $this->makeLoan(['personal' => $personal + ['email' => $case['email'], 'telefono' => $case['telefono']]]);
            try {
                app(LoanDocumentProducer::class)->produce($loan, $this->company(), 'ALLA FIRMA');
                $this->fail('Doveva fallire: '.json_encode($case));
            } catch (DocumentProductionException $e) {
                $this->assertStringContainsString($case['expect'], $e->getMessage());
            }
            $this->assertSame(0, Document::where('documentable_type', 'loan_request')->where('documentable_id', $loan->id)->count());
            $this->assertSame('errore', $loan->fresh()->signature_status);
        }
    }

    public function test_email_e_telefono_scritti_in_modo_diverso_si_normalizzano_per_la_firma(): void
    {
        $this->withModules(1);
        $loan = $this->makeLoan(['personal' => ['tipo_cliente' => 'privato', 'cognome' => 'Rossi', 'nome' => 'Mario', 'codice_fiscale' => 'RSSMRA80A01H501U',
            'email' => '  Mario.Rossi@Example.COM ', 'telefono' => '333 123 4567']]);

        $result = app(LoanDocumentProducer::class)->produce($loan, $this->company(), 'ALLA FIRMA');

        $this->assertSame(['sent' => 1, 'skipped' => 0], $result);
        $signer = DB::connection('core')->table('signature_request_signers')->latest('id')->first();
        $this->assertSame('mario.rossi@example.com', $signer->email);
        $this->assertSame('+393331234567', $signer->phone);
    }

    public function test_dal_pannello_con_un_utente_locale_non_si_scrivono_gli_audit_del_core(): void
    {
        $this->withModules(1);
        $loan = $this->makeLoan();
        // Un utente di unicoagent (tabella locale): il suo id non esiste in users del core.
        $this->actingAs(User::factory()->create(['id' => 424242]));

        $result = app(LoanDocumentProducer::class)->produce($loan, $this->company(), 'ALLA FIRMA');

        $this->assertSame(['sent' => 1, 'skipped' => 0], $result);
        $doc = Document::where('documentable_type', 'loan_request')->where('documentable_id', $loan->id)->first();
        $this->assertNull($doc->created_by);
        $this->assertNull($doc->updated_by);
    }

    public function test_senza_moduli_per_quello_stato_non_fa_nulla_e_non_tocca_lo_stato_di_firma(): void
    {
        $loan = $this->makeLoan();

        $result = app(LoanDocumentProducer::class)->produce($loan, $this->company(), 'ALLA FIRMA');

        $this->assertSame(['sent' => 0, 'skipped' => 0], $result);
        $this->assertNull($loan->fresh()->signature_status);
    }

    public function test_il_documento_porta_la_company_del_core(): void
    {
        $this->withModules(1);
        $loan = $this->makeLoan();

        app(LoanDocumentProducer::class)->produce($loan, $this->company(), 'ALLA FIRMA');

        $this->assertSame($this->coreId, (int) Document::where('documentable_type', 'loan_request')->where('documentable_id', $loan->id)->value('company_id'));
    }
}
