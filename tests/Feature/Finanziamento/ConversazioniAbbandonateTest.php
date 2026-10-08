<?php

namespace Tests\Feature\Finanziamento;

use App\Models\Conversation;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

class ConversazioniAbbandonateTest extends ConversationTestCase
{
    private function aged(Conversation $c, int $minutes): Conversation
    {
        $c->updated_at = now()->subMinutes($minutes);
        $c->saveQuietly();

        return $c;
    }

    public function test_dopo_un_ora_alla_prima_domanda_il_messaggio_riparte_dal_menu(): void
    {
        $this->say('#menu_richiedi');
        $this->aged(Conversation::first(), 61);

        $replies = $this->say('qualunque cosa');

        $this->assertSame('list', $replies[0]->kind);
        $this->assertSame(['menu_richiedi', 'menu_modifica', 'menu_perfeziona', 'menu_stato'], array_keys($replies[0]->options));
        $this->assertSame('annullata', Conversation::first()->status);
    }

    public function test_prima_di_un_ora_la_conversazione_continua(): void
    {
        $this->say('#menu_richiedi');
        $this->aged(Conversation::first(), 30);

        $replies = $this->say('#personale');

        $this->assertSame('attiva', Conversation::first()->status);
        $this->assertSame('importo', Conversation::first()->node);
        $this->assertStringContainsString('Quale importo', $this->bodies($replies));
    }

    public function test_una_scelta_di_menu_dopo_un_ora_apre_il_percorso_scelto(): void
    {
        $this->say('#menu_richiedi');
        $this->aged(Conversation::first(), 90);

        $replies = $this->say('#menu_richiedi');

        $this->assertSame('richiesta', Conversation::latest('id')->first()->flow);
        $this->assertSame('annullata', Conversation::oldest('id')->first()->status);
        $this->assertNotEmpty($replies);
    }

    public function test_dopo_un_ora_ma_a_meta_percorso_vale_la_regola_delle_24_ore(): void
    {
        $this->say('#menu_richiedi', '#personale');
        $this->aged(Conversation::first(), 120);

        $this->say('#imp_5k');

        $this->assertSame('attiva', Conversation::first()->status);
        $this->assertSame('durata', Conversation::first()->node);
    }

    public function test_il_comando_chiude_solo_le_conversazioni_senza_risposte_ferme_da_piu_di_un_ora(): void
    {
        Carbon::setTestNow();
        $vecchia = Conversation::create(['wa_number' => '1', 'flow' => 'richiesta', 'node' => 'prodotto', 'data' => [], 'history' => []]);
        $recente = Conversation::create(['wa_number' => '2', 'flow' => 'richiesta', 'node' => 'prodotto', 'data' => [], 'history' => []]);
        $avviata = Conversation::create(['wa_number' => '3', 'flow' => 'richiesta', 'node' => 'importo', 'data' => ['prodotto' => 'personale'], 'history' => ['prodotto']]);
        $this->aged($vecchia, 120);
        $this->aged($avviata, 120);

        $this->artisan('conversazioni:chiudi-abbandonate')->expectsOutputToContain('Chiuse 1')->assertSuccessful();

        $this->assertSame('annullata', $vecchia->fresh()->status);
        $this->assertSame('attiva', $recente->fresh()->status);
        $this->assertSame('attiva', $avviata->fresh()->status);
    }

    public function test_il_comando_e_pianificato_ogni_ora(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'conversazioni:chiudi-abbandonate'));

        $this->assertCount(1, $events);
        $this->assertSame('0 * * * *', $events->first()->expression);
    }
}
