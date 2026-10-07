<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManualPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_il_manuale_e_raggiungibile_e_copre_agente_e_pannello(): void
    {
        $this->get('/manuale')->assertOk()
            ->assertSee('Manuale utente')
            ->assertSee('Richiedi Finanziamento')
            ->assertSee('Perfeziona Finanziamento')
            ->assertSee('Stato Pratiche')
            ->assertSee('Invio pratica fallito, riprovare o contattare Istruttoria')
            ->assertSee('Segnalatore occasionale')
            ->assertSee('Percorsi di configurazione')
            ->assertSee('Produttori')
            ->assertSee('URL del preventivatore', false)
            ->assertSee('Invia per email (forza)', false);
    }

    public function test_ogni_voce_dell_indice_ha_la_sua_sezione(): void
    {
        $html = $this->get('/manuale')->getContent();

        preg_match_all('/href="#([a-z]+)"/', $html, $links);
        $this->assertGreaterThan(8, count($links[1]));
        foreach (array_unique($links[1]) as $anchor) {
            $this->assertStringContainsString('id="'.$anchor.'"', $html, $anchor);
        }
    }

    public function test_la_navigazione_del_pannello_ha_il_link_al_manuale(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->create());

        $this->get('/admin')->assertOk()->assertSee('Manuale utente')->assertSee('href="/manuale"', false);
    }
}
