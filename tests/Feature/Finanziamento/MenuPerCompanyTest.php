<?php

namespace Tests\Feature\Finanziamento;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Fornitore;

class MenuPerCompanyTest extends ConversationTestCase
{
    private function gbd(): Company
    {
        $company = Company::create(['name' => 'GBD', 'menu' => ['options' => ['menu_richiedi' => 'Nuova offerta', 'menu_perfeziona' => 'Sottoscrivi contratto']]]);
        Fornitore::create(['name' => 'Inst', 'tel' => $this->agent, 'is_active' => true, 'company_id' => $company->id]);

        return $company;
    }

    public function test_la_company_senza_override_usa_il_menu_predefinito(): void
    {
        $this->assertSame(config('finanziamento.menu.options'), Company::create(['name' => 'X'])->menuConfig()['options']);
    }

    public function test_il_menu_dell_installatore_e_quello_della_sua_company(): void
    {
        $this->gbd();

        $options = collect($this->say('ciao'))->flatMap(fn ($r) => $r->options)->all();

        $this->assertContains('Nuova offerta', $options);
        $this->assertNotContains('Modifica Preventivo', $options);
    }

    public function test_si_sceglie_dal_menu_anche_scrivendo_il_titolo_della_company(): void
    {
        $this->gbd();

        $this->say('ciao', 'Nuova offerta');

        $this->assertSame('richiesta', Conversation::latest('id')->first()?->flow);
    }
}
