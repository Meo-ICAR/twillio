<?php

namespace Tests\Feature\Finanziamento;

use App\Models\Company;
use App\Models\LoanRequest;
use App\Models\PraticaDocument;
use Database\Seeders\DocumentCatalogSeeder;
use Illuminate\Support\Facades\Http;

class InvioLeadMediafacileTest extends ConversationTestCase
{
    private function atSummary(): LoanRequest
    {
        config(['finanziamento.crm.driver' => 'mediafacile']);
        Company::create(['name' => 'H', 'url_istruttoria' => 'https://crm.example.com/ws/lead', 'istruttoria_passkey' => 'KEY']);
        $this->seed(DocumentCatalogSeeder::class);
        $loan = LoanRequest::create(['code' => 'FIN-2026-0007', 'agent_wa_number' => $this->agent, 'product' => 'personale',
            'status' => 'informativa_ricevuta', 'privacy_received_at' => now(), 'answers' => ['prodotto' => 'personale', 'importo' => 'imp_5k', 'durata' => 'm24', 'lavoro' => 'dip_priv']]);
        PraticaDocument::populate($loan)->each->update(['status' => 'ricevuto']);
        $this->say('#menu_perfeziona', 'FIN-2026-0007', '#si', 'RSSMRA80A01H501U', 'Rossi', 'Mario', 'Via Roma 1, Milano', '#celibe', '#ci',
            'AB123456', '01/01/2030', '+39 333 1234567', 'mario@example.com', '#si', 'IT60X0542811101000000123456', 'ACME Srl', '01/03/2015');

        return $loan;
    }

    public function test_il_perfezionamento_carica_il_lead_e_ricorda_l_id(): void
    {
        $loan = $this->atSummary();
        Http::fake(['crm.example.com/*' => Http::response('<R><Stato>OK</Stato><IDUU>LEAD-1</IDUU></R>')]);

        $body = $this->bodies($this->say('#conferma'));

        $this->assertStringContainsString('inviata in istruttoria', $body);
        $this->assertSame('perfezionata', $loan->fresh()->status);
        $this->assertSame('LEAD-1', $loan->fresh()->crm_lead_id);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'residenza_provincia=MI') && str_contains($r->url(), 'cognome=Rossi'));
    }

    public function test_se_il_crm_risponde_ko_la_pratica_resta_com_e(): void
    {
        $loan = $this->atSummary();
        Http::fake(['crm.example.com/*' => Http::response('<R><Stato>KO - errore</Stato></R>')]);

        $body = $this->bodies($this->say('#conferma'));

        $this->assertStringContainsString('Invio pratica fallito', $body);
        $this->assertSame('informativa_ricevuta', $loan->fresh()->status);
    }
}
