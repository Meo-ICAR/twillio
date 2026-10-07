<?php

namespace Tests\Feature\Documents;

use App\Models\LoanRequest;
use App\Models\PraticaDocument;
use App\Services\Documents\AgentNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentNotifierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.whatsapp.token' => 'TOK', 'services.whatsapp.phone_number_id' => '555']);
    }

    private function slot(string $status, string $note): PraticaDocument
    {
        $loan = LoanRequest::create(['code' => 'FIN-2026-0001', 'agent_wa_number' => '393331112222', 'product' => 'personale', 'status' => 'informativa_ricevuta', 'answers' => []]);
        $slot = $loan->praticaDocuments()->create(['code' => 'codice_fiscale', 'name' => 'Codice fiscale', 'requirement' => 'obbligatorio', 'status' => $status]);
        $slot->addAnnotation('operatore', $note, 1);

        return $slot;
    }

    public function test_avvisa_l_agente_del_rifiuto_con_la_nota(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'x']]])]);

        $sent = app(AgentNotifier::class)->notify($this->slot('rejected', 'La foto è tagliata'));

        $this->assertTrue($sent);
        Http::assertSent(fn (Request $r) => $r['to'] === '393331112222'
            && str_contains($r['text']['body'], 'FIN-2026-0001')
            && str_contains($r['text']['body'], 'rifiutato')
            && str_contains($r['text']['body'], 'La foto è tagliata')
            && str_contains($r['text']['body'], 'Carica documenti'));
    }

    public function test_avvisa_l_agente_della_richiesta_di_integrazione(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'x']]])]);

        app(AgentNotifier::class)->notify($this->slot('integrazione_richiesta', 'Serve la pagina con la firma'));

        Http::assertSent(fn (Request $r) => str_contains($r['text']['body'], 'integrazione') && str_contains($r['text']['body'], 'Serve la pagina con la firma'));
    }

    public function test_non_scrive_per_gli_altri_stati(): void
    {
        Http::fake();

        foreach (['ok', 'ricevuto', 'da_ricevere'] as $status) {
            $this->assertFalse(app(AgentNotifier::class)->notify($this->slot($status, 'x')));
            LoanRequest::query()->delete();
        }
        Http::assertNothingSent();
    }

    public function test_se_whatsapp_rifiuta_il_messaggio_non_solleva_errori(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'fuori finestra']], 400)]);

        $this->assertFalse(app(AgentNotifier::class)->notify($this->slot('rejected', 'x')));
    }
}
