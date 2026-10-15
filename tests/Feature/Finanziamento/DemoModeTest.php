<?php

namespace Tests\Feature\Finanziamento;

use App\Models\Company;
use App\Models\Fornitore;
use Database\Seeders\GbdEnergySeeder;

/** Installazione demo: avviso iniziale e scelta tra finanziamento e fornitura, cioè la company. */
class DemoModeTest extends ConversationTestCase
{
    protected string $agent = '393331119999';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GbdEnergySeeder::class);
        // Company di finanziamento: la prima, creata prima di GBD.
        Company::oldest('id')->first()?->type === 'ENERGY' && Company::create(['name' => 'Mediatore demo']);
    }

    private function optionTitles(array $replies): array
    {
        return collect($replies)->flatMap(fn ($r) => $r->options)->values()->all();
    }

    public function test_senza_demo_si_vede_subito_il_menu(): void
    {
        config(['demo.enabled' => false]);

        $text = $this->bodies($this->say('ciao'));

        $this->assertStringNotContainsString('DEMO', $text);
        $this->assertNotContains('Fornitura energia', $this->optionTitles($this->say('menu')));
    }

    public function test_in_demo_il_primo_messaggio_avvisa_e_chiede_cosa_provare(): void
    {
        config(['demo.enabled' => true]);

        $replies = $this->say('ciao');

        $this->assertStringContainsString('Installazione DEMO', $this->bodies($replies));
        $this->assertSame(['Finanziamento', 'Fornitura energia'], $this->optionTitles($replies));
        $this->assertNull(Fornitore::findByWhatsApp($this->agent));
    }

    public function test_scegliendo_la_fornitura_il_numero_lavora_con_gbd_energy(): void
    {
        config(['demo.enabled' => true]);
        $this->say('ciao');

        $replies = $this->say('#demo_fornitura');

        $gbd = Company::where('name', 'GBD Energy')->first();
        $this->assertSame($gbd->id, Fornitore::findByWhatsApp($this->agent)->company_id);
        $this->assertStringContainsString('GBD Energy', $this->bodies($replies));
        $this->assertContains('Nuova offerta', $this->optionTitles($replies));
        $this->assertContains('Cambia demo', $this->optionTitles($replies));
        // Scelto una volta, il messaggio dopo mostra il menu e non rifà la domanda.
        $this->assertStringNotContainsString('Installazione DEMO', $this->bodies($this->say('ciao')));
    }

    public function test_scegliendo_il_finanziamento_si_usa_la_company_di_finanziamento(): void
    {
        config(['demo.enabled' => true]);
        $this->say('ciao');

        $replies = $this->say('#demo_finanziamento');

        $company = Company::where(fn ($q) => $q->where('type', '!=', 'ENERGY')->orWhereNull('type'))->oldest('id')->first();
        $this->assertSame($company->id, Fornitore::findByWhatsApp($this->agent)->company_id);
        $this->assertNotContains('Nuova offerta', $this->optionTitles($replies));
        $this->assertContains('Richiedi Finanziamento', $this->optionTitles($replies));
    }

    public function test_demo_e_cambia_demo_fanno_scegliere_di_nuovo_e_cambiano_la_company(): void
    {
        config(['demo.enabled' => true]);
        $this->say('ciao', '#demo_fornitura');

        $this->assertSame(['Finanziamento', 'Fornitura energia'], $this->optionTitles($this->say('/demo')));
        $this->say('#demo_finanziamento');
        $this->assertNotSame(Company::where('name', 'GBD Energy')->value('id'), Fornitore::findByWhatsApp($this->agent)->company_id);

        $this->assertSame(['Finanziamento', 'Fornitura energia'], $this->optionTitles($this->say('#demo_cambia')));
    }

    public function test_un_fornitore_esistente_cambia_company_senza_duplicarsi(): void
    {
        config(['demo.enabled' => true]);
        $gbd = Company::where('name', 'GBD Energy')->first();
        $existing = Fornitore::create(['tel' => $this->agent, 'name' => 'Agente reale', 'type' => 'Agente', 'is_active' => true, 'company_id' => Company::oldest('id')->value('id')]);

        $this->say('ciao', '#demo_fornitura');

        $this->assertSame(1, Fornitore::withTrashed()->where('tel', $this->agent)->count());
        $this->assertSame($gbd->id, $existing->fresh()->company_id);
    }
}
