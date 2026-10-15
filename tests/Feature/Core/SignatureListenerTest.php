<?php

namespace Tests\Feature\Core;

use App\Jobs\SendBpmEvent;
use App\Mail\SignedDocumentsMail;
use App\Models\Company;
use App\Models\LoanRequest;
use App\Services\Documents\LoanDocumentProducer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Unico\Core\Enums\SignatureRequestStatus;
use Unico\Core\Events\SignatureCancelled;
use Unico\Core\Events\SignatureCompleted;
use Unico\Core\Models\Document;
use Unico\Core\Models\DocumentRequirement;
use Unico\Core\Models\SignatureRequest;
use Unico\Core\Models\Task;

class SignatureListenerTest extends CoreTestCase
{
    /** @return array{0: LoanRequest, 1: Company} */
    private function sent(int $docs = 1): array
    {
        Mail::fake();
        Queue::fake();
        $coreId = $this->coreCompany()->id;
        $company = Company::create(['name' => 'GBD', 'produce_documents' => true, 'core_company_id' => $coreId, 'core_tipo_prodotto' => 'Utenza', 'istruttoria_email' => 'gbd@example.com']);
        $task = Task::create(['name' => 'Utenza · ALLA FIRMA '.uniqid(), 'taskable' => 'pratica', 'trigger_field' => 'tipo_prodotto', 'trigger_state' => 'equals', 'trigger_value' => 'Utenza',
            'trigger_subfield' => 'stato_pratica', 'trigger_subvalue' => 'ALLA FIRMA', 'company_id' => $coreId, 'is_active' => true]);
        foreach (range(1, $docs) as $i) {
            DocumentRequirement::create(['task_id' => $task->id, 'document_type_id' => $this->coreModule(null, "Modulo $i")->document_type_id, 'is_required' => true, 'sort_order' => $i]);
        }
        $loan = $this->makeLoan(['status' => 'invio_istruttoria']);
        app(LoanDocumentProducer::class)->produce($loan, $company, 'ALLA FIRMA');

        return [$loan->fresh(), $company];
    }

    private function request(Document $document): SignatureRequest
    {
        return SignatureRequest::where('document_id', $document->id)->firstOrFail();
    }

    private function complete(Document $document): void
    {
        $document->update(['is_signed' => true, 'signed_at' => now()]);
        event(new SignatureCompleted($this->request($document), $document->fresh()));
    }

    public function test_a_firme_complete_la_pratica_passa_a_perfezionare_con_mail_e_evento(): void
    {
        [$loan] = $this->sent(1);

        $this->complete(Document::where('documentable_id', $loan->id)->first());

        $loan->refresh();
        $this->assertSame('perfezionare', $loan->status);
        $this->assertSame('firmata', $loan->signature_status);
        $this->assertNotNull($loan->signed_at);
        Mail::assertSent(SignedDocumentsMail::class, fn ($m) => $m->hasTo('gbd@example.com'));
        Queue::assertPushed(SendBpmEvent::class, 1);
    }

    public function test_a_firme_complete_si_prova_ad_avvisare_l_installatore_senza_chiamate_vere(): void
    {
        [$loan] = $this->sent(1);

        $this->complete(Document::where('documentable_id', $loan->id)->first());

        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => str_contains($r->url(), 'graph.facebook.com') && $r->hasHeader('Authorization', 'Bearer TEST-TOKEN'));
    }

    public function test_con_piu_documenti_si_aspetta_l_ultima_firma(): void
    {
        [$loan] = $this->sent(2);
        $docs = Document::where('documentable_id', $loan->id)->get();

        $this->complete($docs[0]);
        $this->assertSame('invio_istruttoria', $loan->fresh()->status);
        Mail::assertNothingSent();

        $this->complete($docs[1]);
        $this->assertSame('perfezionare', $loan->fresh()->status);
    }

    public function test_un_evento_ripetuto_non_manda_due_mail_ne_due_eventi(): void
    {
        [$loan] = $this->sent(1);
        $doc = Document::where('documentable_id', $loan->id)->first();

        $this->complete($doc);
        $this->complete($doc);

        Mail::assertSent(SignedDocumentsMail::class, 1);
        Queue::assertPushed(SendBpmEvent::class, 1);
    }

    public function test_una_firma_scaduta_lascia_la_pratica_trasmessa(): void
    {
        [$loan] = $this->sent(1);
        $doc = Document::where('documentable_id', $loan->id)->first();
        $request = $this->request($doc);
        $request->update(['status' => SignatureRequestStatus::Expired]);

        event(new SignatureCancelled($request->fresh()));

        $loan->refresh();
        $this->assertSame('invio_istruttoria', $loan->status);
        $this->assertSame('scaduta', $loan->signature_status);
        Mail::assertNothingSent();
    }

    public function test_una_firma_rifiutata_si_segna_come_tale(): void
    {
        [$loan] = $this->sent(1);
        $doc = Document::where('documentable_id', $loan->id)->first();
        $request = $this->request($doc);
        $request->update(['status' => SignatureRequestStatus::Declined]);

        event(new SignatureCancelled($request->fresh()));

        $this->assertSame('rifiutata', $loan->fresh()->signature_status);
    }

    public function test_un_documento_non_legato_a_una_pratica_si_ignora(): void
    {
        $this->sent(1);
        $module = $this->coreModule();
        $doc = Document::create(['documentable_type' => 'company', 'documentable_id' => $this->coreCompany()->id, 'document_type_id' => $module->document_type_id, 'name' => 'Altro']);
        $request = SignatureRequest::create(['document_id' => $doc->id, 'provider' => 'fake', 'status' => SignatureRequestStatus::Signed]);

        event(new SignatureCompleted($request, $doc));

        Mail::assertNothingSent();
    }
}
