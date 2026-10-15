<?php

namespace Tests\Feature\Core;

use App\Jobs\SendBpmEvent;
use App\Mail\LoanSubmissionMail;
use App\Models\Company;
use App\Models\LoanRequest;
use App\Services\Conversation\ConversationEngine;
use App\Services\Conversation\IncomingMessage;
use Database\Seeders\FlowSeeder;
use Database\Seeders\GbdEnergySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Unico\Core\Models\DocumentRequirement;
use Unico\Core\Models\SignatureRequest;
use Unico\Core\Models\Task;

class PerfezionamentoConDocumentiTest extends CoreTestCase
{
    protected string $agent = '393927968199';

    private int $coreId;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Queue::fake();
        config(['finanziamento.mail.to' => 'istruttoria@example.com', 'services.whatsapp.token' => 'T', 'services.whatsapp.phone_number_id' => '1']);

        // L'agente di GBD sta nel core con company_id = id locale della company: nel DB di test serve una company core con quell'id.
        DB::connection('core')->table('companies')->insertOrIgnore(['id' => 1, 'name' => 'Specchio locale', 'created_at' => now(), 'updated_at' => now()]);
        $this->seed(FlowSeeder::class);
        $this->seed(GbdEnergySeeder::class);

        $this->coreId = $this->coreCompany()->id;
        Company::where('name', 'GBD Energy')->update(['produce_documents' => true, 'core_company_id' => $this->coreId, 'core_tipo_prodotto' => 'Utenza']);
    }

    private function say(string ...$inputs): array
    {
        $engine = app(ConversationEngine::class);
        $replies = [];
        foreach ($inputs as $i) {
            $replies = $engine->handle(str_starts_with($i, '#') ? new IncomingMessage($this->agent, 'interactive', $i, substr($i, 1)) : new IncomingMessage($this->agent, 'text', $i));
        }

        return $replies;
    }

    private function bodies(array $replies): string
    {
        return implode(' ', array_map(fn ($r) => $r->body, $replies));
    }

    private function toSummary(): string
    {
        $this->say('ciao', '#menu_richiedi', '#luce', '3000', '600', '#conferma');
        $code = LoanRequest::latest('id')->value('code');
        $this->say('ciao', '#menu_perfeziona');
        $this->say($code, '#si', '#privato', 'RSSMRA80A01H501U', 'Rossi', 'Mario', 'Via Roma 1, 00100 Roma', '#si', 'IT001E12345678', 'enel energia', '3331234567', 'mario@example.com', '#si');

        return $code;
    }

    private function signTask(): void
    {
        $task = Task::create(['name' => 'Utenza · ALLA FIRMA '.uniqid(), 'taskable' => 'pratica', 'trigger_field' => 'tipo_prodotto', 'trigger_state' => 'equals', 'trigger_value' => 'Utenza',
            'trigger_subfield' => 'stato_pratica', 'trigger_subvalue' => 'ALLA FIRMA', 'company_id' => $this->coreId, 'is_active' => true]);
        DocumentRequirement::create(['task_id' => $task->id, 'document_type_id' => $this->coreModule(null, 'Informativa')->document_type_id, 'is_required' => true, 'sort_order' => 1]);
    }

    public function test_al_conferma_la_pratica_e_trasmessa_e_i_documenti_partono_in_firma(): void
    {
        $this->signTask();
        $code = $this->toSummary();

        $replies = $this->say('#conferma');

        $loan = LoanRequest::where('code', $code)->first();
        $this->assertSame('invio_istruttoria', $loan->status);
        $this->assertSame('in_attesa', $loan->signature_status);
        $this->assertSame(1, SignatureRequest::whereIn('document_id', \Unico\Core\Models\Document::where('documentable_id', $loan->id)->pluck('id'))->count());
        Mail::assertSent(LoanSubmissionMail::class, fn ($m) => $m->hasTo('contratti@gbdenergy.example'));
        $this->assertStringContainsString('firma', $this->bodies($replies));
    }

    public function test_l_evento_bpm_non_parte_al_conferma(): void
    {
        $this->signTask();
        $this->toSummary();

        $this->say('#conferma');

        Queue::assertNotPushed(SendBpmEvent::class);
    }

    public function test_se_la_produzione_fallisce_la_pratica_resta_trasmessa_con_errore_di_firma(): void
    {
        $this->signTask();
        $code = $this->toSummary();
        Storage::disk(config('unico-core.pdf.modules_disk', 'public'))->deleteDirectory('module');

        $replies = $this->say('#conferma');

        $loan = LoanRequest::where('code', $code)->first();
        $this->assertSame('invio_istruttoria', $loan->status);
        $this->assertSame('errore', $loan->signature_status);
        Mail::assertSent(LoanSubmissionMail::class);
        $this->assertStringContainsString('pannello', $this->bodies($replies));
    }

    public function test_senza_il_flag_il_comportamento_resta_quello_di_oggi(): void
    {
        Company::where('name', 'GBD Energy')->update(['produce_documents' => false]);
        $code = $this->toSummary();

        $this->say('#conferma');

        $loan = LoanRequest::where('code', $code)->first();
        $this->assertSame('perfezionata', $loan->status);
        $this->assertNull($loan->signature_status);
        Queue::assertPushed(SendBpmEvent::class);
    }

    public function test_senza_task_per_lo_stato_la_pratica_e_trasmessa_senza_documenti(): void
    {
        $code = $this->toSummary();

        $this->say('#conferma');

        $loan = LoanRequest::where('code', $code)->first();
        $this->assertSame('invio_istruttoria', $loan->status);
        $this->assertNull($loan->signature_status);
    }
}
