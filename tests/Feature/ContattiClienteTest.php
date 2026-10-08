<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\LoanRequest;
use App\Models\PraticaDocument;
use App\Models\User;
use Database\Seeders\DocumentCatalogSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Finanziamento\ConversationTestCase;

class ContattiClienteTest extends ConversationTestCase
{
    private function atEmail(string $answerContact): LoanRequest
    {
        $this->seed(DocumentCatalogSeeder::class);
        $loan = LoanRequest::create(['code' => 'PM-1007-1000', 'agent_wa_number' => $this->agent, 'product' => 'personale',
            'status' => 'informativa_ricevuta', 'privacy_received_at' => now(), 'answers' => ['prodotto' => 'personale']]);
        PraticaDocument::populate($loan)->each->update(['status' => 'ricevuto']);
        $this->say('#menu_perfeziona', '#perfeziona:PM-1007-1000', '#si', 'RSSMRA80A01H501U', 'Rossi', 'Mario', 'Via Roma 1', '#celibe', '#ci',
            'AB123456', '01/01/2030', '+39 333 1234567', 'mario@example.com');

        return $loan;
    }

    public function test_dopo_l_email_si_chiede_se_si_puo_contattare_il_cliente_per_i_documenti(): void
    {
        $this->atEmail('si');

        $this->assertSame('contatto_diretto', Conversation::where('status', 'attiva')->first()->node);
        $replies = $this->say('boh');
        $this->assertStringContainsString('Scegli una delle opzioni', $this->bodies($replies));
        $this->assertSame(['si' => 'Sì', 'no' => 'No'], end($replies)->options);
    }

    public function test_alla_fine_la_pratica_ha_cellulare_email_e_contatto_diretto(): void
    {
        $loan = $this->atEmail('si');
        $this->say('#si', 'IT60X0542811101000000123456', 'ACME Srl', '01/03/2015', '#conferma');

        $loan->refresh();
        $this->assertSame('perfezionata', $loan->status);
        $this->assertTrue($loan->direct_contact);
        $this->assertSame('+393331234567', $loan->customer_phone);
        $this->assertSame('mario@example.com', $loan->customer_email);
    }

    public function test_rispondendo_no_il_contatto_diretto_e_spento(): void
    {
        $loan = $this->atEmail('no');
        $this->say('#no', 'IT60X0542811101000000123456', 'ACME Srl', '01/03/2015', '#conferma');

        $this->assertFalse($loan->fresh()->direct_contact);
        $this->assertSame('mario@example.com', $loan->fresh()->customer_email);
    }

    public function test_i_recapiti_del_cliente_sono_cifrati_nel_database(): void
    {
        $loan = $this->atEmail('si');
        $this->say('#si', 'IT60X0542811101000000123456', 'ACME Srl', '01/03/2015', '#conferma');

        $raw = DB::table('loan_requests')->where('id', $loan->id)->first();
        $this->assertStringNotContainsString('mario@example.com', (string) $raw->customer_email);
        $this->assertStringNotContainsString('1234567', (string) $raw->customer_phone);
    }

    public function test_senza_invio_riuscito_i_recapiti_non_vengono_scritti(): void
    {
        config(['finanziamento.mail.to' => null]);
        $loan = $this->atEmail('si');

        $this->say('#si', 'IT60X0542811101000000123456', 'ACME Srl', '01/03/2015', '#conferma');

        $loan->refresh();
        $this->assertNotSame('perfezionata', $loan->status);
        $this->assertNull($loan->customer_phone);
        $this->assertNull($loan->customer_email);
        $this->assertFalse($loan->direct_contact);
    }

    public function test_la_scheda_della_pratica_mostra_i_recapiti_e_il_contatto_diretto(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->create());
        $loan = LoanRequest::create(['code' => 'PM-1', 'agent_wa_number' => '39', 'product' => 'personale', 'status' => 'perfezionata', 'answers' => [],
            'direct_contact' => true, 'customer_phone' => '+393331234567', 'customer_email' => 'mario@example.com']);

        $this->get("/admin/loan-requests/{$loan->id}")->assertOk()
            ->assertSee('Cellulare cliente')->assertSee('+393331234567')->assertSee('mario@example.com')->assertSee('Contatto diretto col cliente');
    }
}
