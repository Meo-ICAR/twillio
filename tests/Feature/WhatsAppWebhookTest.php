<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\LoanRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WhatsAppWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.whatsapp.token' => 'TOK', 'services.whatsapp.phone_number_id' => '555']);
    }

    private function text(string $body): array
    {
        return ['entry' => [['changes' => [['value' => ['messages' => [
            ['from' => '393331112222', 'type' => 'text', 'text' => ['body' => $body]],
        ]]]]]]];
    }

    public function test_un_testo_riceve_il_menu_a_lista(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'x']]])]);

        $this->postJson('/api/whatsapp/webhook', $this->text('ciao'))
            ->assertOk()->assertJson(['status' => 'EVENT_RECEIVED']);

        Http::assertSent(fn (Request $r) => $r['to'] === '393331112222'
            && ($r['interactive']['type'] ?? null) === 'list'
            && $r['interactive']['action']['sections'][0]['rows'][0]['title'] === 'Richiedi Finanziamento');
        Http::assertSent(fn (Request $r) => str_contains($r['text']['body'] ?? '', '/comandi'));
    }

    public function test_gli_eventi_di_stato_non_fanno_nulla(): void
    {
        Http::fake();
        $statuses = ['entry' => [['changes' => [['value' => ['statuses' => [['status' => 'delivered']]]]]]]];

        $this->postJson('/api/whatsapp/webhook', $statuses)->assertOk();
        $this->postJson('/api/whatsapp/webhook', [])->assertOk();

        Http::assertNothingSent();
    }

    public function test_la_conversazione_parte_e_invia_la_prima_domanda(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([])]);

        $this->postJson('/api/whatsapp/webhook', $this->text('1'))->assertOk();

        $this->assertSame('prodotto', Conversation::first()->node);
        Http::assertSent(fn (Request $r) => str_contains($r['interactive']['body']['text'] ?? '', 'Che tipo di finanziamento'));
    }

    public function test_se_l_invio_fallisce_lo_stato_non_avanza(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'no']], 500)]);

        $this->postJson('/api/whatsapp/webhook', $this->text('1'))->assertOk();

        $this->assertSame(0, Conversation::count());
    }

    public function test_un_errore_interno_non_fa_ripetere_i_retry_a_meta(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([])]);
        Conversation::create(['wa_number' => '393331112222', 'flow' => 'richiesta', 'node' => 'nodo_inesistente', 'data' => [], 'history' => []]);

        $this->postJson('/api/whatsapp/webhook', $this->text('ciao'))->assertOk();
    }

    public function test_la_verifica_del_webhook_resta_invariata(): void
    {
        config(['services.whatsapp.verify_token' => 'UnicoAgent']);

        $this->get('/api/whatsapp/webhook?hub.mode=subscribe&hub.verify_token=UnicoAgent&hub.challenge=abc')
            ->assertOk()->assertSee('abc');
        $this->get('/api/whatsapp/webhook?hub.mode=subscribe&hub.verify_token=x&hub.challenge=abc')->assertForbidden();
    }

    public function test_un_invio_fallito_non_lascia_file_orfani(): void
    {
        Storage::fake('local');
        Http::fake([
            'graph.facebook.com/v20.0/555/messages' => Http::response(['error' => ['message' => 'no']], 500),
            'graph.facebook.com/v20.0/M1' => Http::response(['url' => 'https://lookaside.fbsbx.com/f', 'mime_type' => 'application/pdf']),
            'lookaside.fbsbx.com/*' => Http::response('BYTES'),
        ]);
        $loan = LoanRequest::create([
            'code' => 'FIN-2026-0001', 'agent_wa_number' => '393331112222', 'product' => 'personale',
            'status' => 'in_attesa_informativa', 'answers' => ['prodotto' => 'personale'],
        ]);
        Conversation::create([
            'wa_number' => '393331112222', 'flow' => 'perfezionamento', 'node' => 'informativa',
            'loan_request_id' => $loan->id, 'data' => [], 'history' => [],
        ]);
        $payload = ['entry' => [['changes' => [['value' => ['messages' => [
            ['from' => '393331112222', 'type' => 'document', 'document' => ['id' => 'M1', 'mime_type' => 'application/pdf']],
        ]]]]]]];

        $this->postJson('/api/whatsapp/webhook', $payload)->assertOk();

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, Attachment::count());
        $this->assertNull($loan->fresh()->privacy_received_at);
    }
}
