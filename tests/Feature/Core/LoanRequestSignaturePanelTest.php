<?php

namespace Tests\Feature\Core;

use App\Filament\Resources\LoanRequests\Pages\ViewLoanRequest;
use App\Models\Company;
use App\Models\User;
use Livewire\Livewire;

class LoanRequestSignaturePanelTest extends CoreTestCase
{
    public function test_la_pratica_mostra_lo_stato_di_firma(): void
    {
        $loan = $this->makeLoan(['status' => 'invio_istruttoria', 'signature_status' => 'in_attesa']);
        $this->actingAs(User::factory()->create());

        Livewire::test(ViewLoanRequest::class, ['record' => $loan->getKey()])->assertSee('In attesa firma')->assertSee('Trasmessa in istruttoria');
    }

    public function test_reinvia_e_visibile_solo_dopo_un_errore_di_firma(): void
    {
        Company::create(['name' => 'GBD', 'produce_documents' => true, 'core_company_id' => $this->coreCompany()->id, 'core_tipo_prodotto' => 'Utenza']);
        $loan = $this->makeLoan(['signature_status' => 'in_attesa']);
        $this->actingAs(User::factory()->create());

        Livewire::test(ViewLoanRequest::class, ['record' => $loan->getKey()])->assertActionHidden('reinviaFirma');

        $loan->update(['signature_status' => 'errore']);

        Livewire::test(ViewLoanRequest::class, ['record' => $loan->getKey()])->assertActionVisible('reinviaFirma');
    }

    public function test_senza_stato_di_firma_la_sezione_non_compare(): void
    {
        $loan = $this->makeLoan();
        $this->actingAs(User::factory()->create());

        Livewire::test(ViewLoanRequest::class, ['record' => $loan->getKey()])->assertDontSee('Stato di firma');
    }

    public function test_la_riga_della_pratica_nel_bot_riporta_lo_stato_di_firma(): void
    {
        $loan = $this->makeLoan(['status' => 'invio_istruttoria', 'signature_status' => 'in_attesa']);
        $method = new \ReflectionMethod(\App\Services\Conversation\ConversationEngine::class, 'loanLine');
        $method->setAccessible(true);

        $line = $method->invoke(app(\App\Services\Conversation\ConversationEngine::class), $loan);

        $this->assertStringContainsString('firma: in attesa firma', $line);
        $this->assertStringContainsString('invio istruttoria', $line);
    }
}
