<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\AttentionWidget;
use App\Filament\Widgets\IntegrationsWidget;
use App\Filament\Widgets\WeekWidget;
use App\Models\Attachment;
use App\Models\LoanRequest;
use App\Models\PraticaDocument;
use App\Models\User;
use App\Services\Conversation\Reply;
use App\Services\Documents\DocumentReader;
use App\Services\SystemHealth;
use App\Services\Whatsapp\WhatsAppClient;
use Database\Seeders\DocumentCatalogSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->create());
        $this->seed(DocumentCatalogSeeder::class);
    }

    private function loan(string $code, string $status = 'richiesta', array $o = []): LoanRequest
    {
        return LoanRequest::create($o + ['code' => $code, 'agent_wa_number' => '39', 'product' => 'personale', 'status' => $status, 'answers' => []]);
    }

    public function test_la_dashboard_mostra_i_tre_blocchi_e_non_i_widget_di_default(): void
    {
        $this->get('/admin')->assertOk()->assertSeeLivewire(AttentionWidget::class)->assertSeeLivewire(IntegrationsWidget::class)->assertSeeLivewire(WeekWidget::class)->assertDontSee('Filament v');
    }

    public function test_conta_cio_che_richiede_intervento(): void
    {
        $loan = $this->loan('FIN-1', 'perfezionata', ['perfected_at' => now()->subDays(2)]);
        $this->loan('FIN-2', 'perfezionata', ['perfected_at' => now()->subDays(20)]);
        PraticaDocument::populate($loan);
        $loan->praticaDocuments()->where('code', 'documento_identita')->update(['status' => 'rejected']);
        $loan->praticaDocuments()->where('code', 'informativa')->update(['status' => 'ricevuto']);
        Attachment::create(['loan_request_id' => $loan->id, 'kind' => 'reddito', 'path' => 'x', 'mime' => 'image/jpeg', 'status' => 'non_analizzato', 'received_at' => now()]);

        $a = app(SystemHealth::class)->attention();

        $this->assertSame(['documenti_rifiutati' => 1, 'non_analizzati' => 1, 'informative_in_attesa' => 1, 'perfezionate_7g' => 1], $a);
        Livewire::test(AttentionWidget::class)->assertSee('Documenti rifiutati')->assertSee('Informative da verificare');
    }

    public function test_whatsapp_registra_l_esito_dell_ultimo_invio(): void
    {
        config(['services.whatsapp.token' => 'T', 'services.whatsapp.phone_number_id' => '1']);
        $this->assertNull(app(SystemHealth::class)->whatsapp());
        Livewire::test(IntegrationsWidget::class)->assertSee('Nessun invio');

        Http::fake(['graph.facebook.com/*' => Http::sequence()->push(['error' => ['message' => 'expired']], 401)->push(['messages' => [['id' => 'a']]])]);
        app(WhatsAppClient::class)->send('39', Reply::text('x'));
        $w = app(SystemHealth::class)->whatsapp();
        $this->assertFalse($w['ok']);
        $this->assertSame(401, $w['status']);
        Livewire::test(IntegrationsWidget::class)->assertSee('Invio fallito')->assertSee('token');

        app(WhatsAppClient::class)->send('39', Reply::text('x'));
        $this->assertTrue(app(SystemHealth::class)->whatsapp()['ok']);
        Livewire::test(IntegrationsWidget::class)->assertSee('Funziona');
    }

    public function test_la_pulizia_mai_eseguita_si_segnala_e_dopo_il_comando_no(): void
    {
        Livewire::test(IntegrationsWidget::class)->assertSee('Mai eseguita');

        $this->artisan('finanziamento:purge')->assertSuccessful();

        $this->assertNotNull(app(SystemHealth::class)->lastPurge());
        Livewire::test(IntegrationsWidget::class)->assertDontSee('Mai eseguita');
    }

    public function test_il_dry_run_non_conta_come_pulizia_eseguita(): void
    {
        $this->artisan('finanziamento:purge --dry-run')->assertSuccessful();

        $this->assertNull(app(SystemHealth::class)->lastPurge());
    }

    public function test_analisi_in_corso_e_fallite(): void
    {
        $loan = $this->loan('FIN-1');
        $slot = PraticaDocument::populate($loan)->firstWhere('code', 'documento_identita');
        Attachment::create(['loan_request_id' => $loan->id, 'pratica_document_id' => $slot->id, 'kind' => 'documento_identita', 'path' => 'x', 'mime' => 'image/jpeg', 'status' => 'ricevuto', 'received_at' => now()->subMinutes(5)]);
        Attachment::create(['loan_request_id' => $loan->id, 'kind' => 'reddito', 'path' => 'y', 'mime' => 'image/jpeg', 'status' => 'non_leggibile', 'received_at' => now()->subHours(2)]);

        $ai = app(SystemHealth::class)->analysis();

        $this->assertSame(1, $ai['pending']);
        $this->assertSame(1, $ai['failed_24h']);
        $this->assertNotNull($ai['oldest_pending']);
    }

    public function test_numeri_della_settimana(): void
    {
        $this->loan('FIN-1');
        $this->loan('FIN-2', 'perfezionata');
        $this->loan('FIN-3', 'richiesta', ['created_at' => now()->subDays(25)]);
        $this->loan('FIN-4', 'richiesta', ['created_at' => now()->subDays(40)]);

        $w = app(SystemHealth::class)->week();

        $this->assertEquals(['richiesta' => 1, 'perfezionata' => 1], $w['per_stato']);
        $this->assertSame(2, $w['in_scadenza'], 'oltre 23 giorni (30 - 7), compresa quella già scaduta');
        Livewire::test(WeekWidget::class)->assertSee('Pratiche nuove')->assertSee('Conversazioni attive');
    }

    public function test_la_verifica_whatsapp_legge_il_numero_senza_mandare_messaggi(): void
    {
        config(['services.whatsapp.token' => 'T', 'services.whatsapp.phone_number_id' => '1']);
        Http::fake(['graph.facebook.com/*' => Http::response(['verified_name' => 'Unico', 'display_phone_number' => '+39 1'])]);

        $r = app(SystemHealth::class)->checkWhatsApp();

        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('Unico', $r['detail']);
        Http::assertSent(fn ($req) => $req->method() === 'GET' && ! str_contains($req->url(), '/messages'));
        $this->assertTrue(app(SystemHealth::class)->whatsapp()['ok']);
    }

    public function test_la_verifica_whatsapp_con_token_scaduto_segnala_il_problema(): void
    {
        config(['services.whatsapp.token' => 'T', 'services.whatsapp.phone_number_id' => '1']);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Session has expired']], 401)]);

        $r = app(SystemHealth::class)->checkWhatsApp();

        $this->assertFalse($r['ok']);
        $this->assertSame('Session has expired', $r['detail']);
        $this->assertFalse(app(SystemHealth::class)->whatsapp()['ok']);
    }

    public function test_la_verifica_ai_distingue_chiave_credito_e_funzionamento(): void
    {
        $h = app(SystemHealth::class);
        config(['services.anthropic.key' => null]);
        $this->assertFalse($h->checkAi()['ok']);

        config(['services.anthropic.key' => 'k']);
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['content' => []])
            ->push(['error' => ['message' => 'Your credit balance is too low to access the Anthropic API.']], 400)
            ->push(['error' => ['message' => 'invalid x-api-key']], 401)]);

        $this->assertTrue($h->checkAi()['ok']);
        $this->assertSame('Credito esaurito: ricaricare.', $h->checkAi()['detail']);
        $this->assertSame('Chiave non valida.', $h->checkAi()['detail']);
        $this->assertFalse($h->aiCheck()['ok']);
        Http::assertSent(fn ($r) => $r->hasHeader('x-api-key', 'k') && $r['max_tokens'] === 1);
    }

    public function test_il_widget_mostra_l_esito_della_verifica_ai(): void
    {
        config(['services.anthropic.key' => 'k']);
        $this->app->instance(DocumentReader::class, new class implements DocumentReader
        {
            public function enabled(): bool
            {
                return true;
            }

            public function read(string $kind, string $mime, string $bytes): ?array
            {
                return null;
            }
        });
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'credit balance is too low']], 400)]);
        app(SystemHealth::class)->checkAi();

        Livewire::test(IntegrationsWidget::class)->assertSee('Credito esaurito');
    }

    public function test_la_dashboard_ha_i_due_pulsanti_di_verifica(): void
    {
        Livewire::test(Dashboard::class)->assertActionExists('verificaWhatsapp')->assertActionExists('verificaAi');
    }
}
