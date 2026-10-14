<?php

namespace Tests\Feature\Finanziamento;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\Fornitore;

class ConversationCompanyTest extends ConversationTestCase
{
    public function test_un_numero_sconosciuto_con_due_company_usa_la_piu_vecchia_senza_errori(): void
    {
        Company::create(['name' => 'Prima']);
        Company::create(['name' => 'Seconda']);
        Flow::query()->update(['company_id' => Company::oldest('id')->value('id')]);

        $this->assertNotEmpty($this->say('ciao'));
    }

    public function test_una_company_senza_percorsi_riceve_un_messaggio_chiaro(): void
    {
        Company::create(['name' => 'Altra']);
        $vuota = Company::create(['name' => 'Vuota']);
        Flow::query()->update(['company_id' => Company::oldest('id')->value('id')]);
        Fornitore::create(['name' => 'Agente', 'tel' => $this->agent, 'is_active' => true, 'company_id' => $vuota->id]);

        $replies = $this->say('ciao', '#menu_richiedi');

        $this->assertStringContainsString('non è ancora attivo', $this->bodies($replies));
    }

    public function test_la_conversazione_porta_la_company_dell_agente(): void
    {
        $company = Company::create(['name' => 'Titolare']);
        Flow::query()->update(['company_id' => $company->id]);
        Fornitore::create(['name' => 'Agente', 'tel' => $this->agent, 'is_active' => true, 'company_id' => $company->id]);

        $this->say('ciao', '#menu_richiedi');

        $this->assertSame($company->id, Conversation::latest('id')->first()->company_id);
    }
}
