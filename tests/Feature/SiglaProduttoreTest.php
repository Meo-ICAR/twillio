<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Fornitore;
use App\Models\LoanRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Finanziamento\ConversationTestCase;

class SiglaProduttoreTest extends ConversationTestCase
{
    use RefreshDatabase;

    private const FLOW = ['#menu_richiedi', '#personale', '#imp_5k', '#m24', '#dip_priv', '#det', '#anz_1', '#red_1000', '#no', '#no', '#conferma'];

    private function producer(array $o = []): Fornitore
    {
        return Fornitore::create($o + ['tel' => '3331112222', 'is_active' => true]);
    }

    public function test_la_sigla_inserita_si_conserva(): void
    {
        $this->assertSame('ABC', $this->producer(['name' => 'Rossi Mario', 'sigla' => 'ABC'])->ensureSigla());
    }

    public function test_senza_sigla_si_ricava_dalle_iniziali_e_si_salva(): void
    {
        $f = $this->producer(['name' => 'Piero Meo']);

        $this->assertSame('PM', $f->ensureSigla());
        $this->assertSame('PM', $f->fresh()->sigla);
    }

    public function test_con_tre_o_piu_parole_si_prendono_le_prime_tre_iniziali(): void
    {
        $this->assertSame('ABC', $this->producer(['name' => 'Agenzia Bianchi Credito Srl'])->ensureSigla());
    }

    public function test_con_una_parola_sola_si_prendono_le_prime_tre_lettere_senza_accenti(): void
    {
        $this->assertSame('ELI', $this->producer(['name' => 'Èlite'])->ensureSigla());
        $this->assertSame('MAR', $this->producer(['name' => 'Marco', 'tel' => '3330000001'])->ensureSigla());
    }

    public function test_si_usa_il_referente_se_manca_la_denominazione(): void
    {
        $this->assertSame('AV', $this->producer(['name' => null, 'nome' => 'Anna Verdi'])->ensureSigla());
    }

    public function test_senza_nome_vale_seg(): void
    {
        $this->assertSame('SEG', $this->producer(['name' => null])->ensureSigla());
    }

    public function test_il_preventivo_di_un_produttore_inizia_con_la_sua_sigla_e_dice_quando_e_stato_fatto(): void
    {
        $this->producer(['name' => 'Piero Meo']);
        $this->travelTo('2026-10-07 14:35:00');

        $replies = $this->say(...self::FLOW);

        $this->assertSame('PM-1007-1435', LoanRequest::first()->code);
        $this->assertStringContainsString('*PM-1007-1435*', end($replies)->body);
        $this->assertSame('PM', Fornitore::first()->sigla, 'la sigla ricavata resta salvata');
    }

    public function test_un_segnalatore_occasionale_usa_seg(): void
    {
        $this->travelTo('2026-10-07 14:35:00');

        $this->say(...self::FLOW);

        $this->assertSame('SEG-1007-1435', LoanRequest::first()->code);
    }

    public function test_un_secondo_preventivo_nello_stesso_minuto_prende_una_lettera(): void
    {
        $this->producer(['name' => 'Piero Meo']);
        $this->travelTo('2026-10-07 14:35:00');

        $this->say(...self::FLOW);
        $this->say(...self::FLOW);

        $this->assertSame(['PM-1007-1435', 'PM-1007-1435A'], LoanRequest::orderBy('id')->pluck('code')->all());
    }

    public function test_il_codice_nuovo_si_puo_scrivere_a_mano_per_perfezionare(): void
    {
        $this->producer(['name' => 'Piero Meo']);
        $this->travelTo('2026-10-07 14:35:00');
        $this->say(...self::FLOW);

        $replies = $this->say('pm-1007-1435');

        $this->assertSame('conferma_pratica', Conversation::where('status', 'attiva')->first()->node);
        $this->assertStringContainsString('È la pratica giusta?', $this->bodies($replies));
    }
}
