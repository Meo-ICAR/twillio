<?php

namespace Tests\Feature\Flows;

use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\FlowNodeJump;
use App\Services\Flows\FlowRepository;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Finanziamento\ConversationTestCase;

class FlowJumpsTest extends ConversationTestCase
{
    private function node(string $code, string $flow = 'richiesta'): FlowNode
    {
        return FlowNode::where('flow_id', Flow::where('code', $flow)->value('id'))->where('code', $code)->firstOrFail();
    }

    private function jumps(FlowNode $node): array
    {
        return $node->jumps()->get()->map(fn ($j) => [$j->when_value, $j->go_to])->all();
    }

    public function test_i_salti_hanno_la_loro_tabella_e_le_vecchie_colonne_non_ci_sono_piu(): void
    {
        $this->assertTrue(Schema::hasColumns('flow_node_jumps', ['flow_node_id', 'when_value', 'go_to', 'sort_order']));
        $this->assertTrue(Schema::hasColumn('flow_nodes', 'jump_by'));
        $this->assertFalse(Schema::hasColumn('flow_nodes', 'next_to'));
        $this->assertFalse(Schema::hasColumn('flow_nodes', 'next_map'));
    }

    public function test_l_importazione_scrive_i_salti_come_righe(): void
    {
        $this->assertSame([['*', 'durata']], $this->jumps($this->node('importo')));
        $this->assertSame('answer', $this->node('importo')->jump_by);

        $durata = $this->node('durata');
        $this->assertSame('prodotto', $durata->jump_by);
        $this->assertSame(['personale', 'quinto', 'finalizzato', 'leasing', 'aziendale'], $durata->jumps->pluck('when_value')->all());
        $this->assertContains(['*', 'impegni'], $this->jumps($this->node('lavoro')));
        $this->assertSame([], $this->jumps($this->node('riepilogo')), 'il riepilogo non ha salti');
    }

    public function test_il_repository_ricompone_i_salti_come_nella_configurazione(): void
    {
        $repo = app(FlowRepository::class);

        $this->assertSame('durata', $repo->node('richiesta', 'importo')['next'], 'un solo salto predefinito = salto fisso');
        $this->assertSame('prodotto', $repo->node('richiesta', 'durata')['next_by']);
        $this->assertSame(config('finanziamento.flows.richiesta.nodes.lavoro.next'), $repo->node('richiesta', 'lavoro')['next']);
        $this->assertArrayNotHasKey('next_by', $repo->node('richiesta', 'importo'));
        $this->assertArrayNotHasKey('next', $repo->node('richiesta', 'riepilogo'));
    }

    public function test_cambiando_il_salto_fisso_il_bot_segue_il_nuovo_percorso(): void
    {
        FlowNodeJump::where('flow_node_id', $this->node('importo')->id)->update(['go_to' => 'riepilogo']);
        $this->node('importo')->touch();

        $this->say('#menu_richiedi', '#personale', '#imp_5k');

        $this->assertSame('riepilogo', Conversation::first()->node);
    }

    public function test_si_aggiunge_un_salto_per_una_risposta_specifica(): void
    {
        $prodotto = $this->node('prodotto');
        $prodotto->jumps()->where('when_value', 'mutuo')->first()->update(['go_to' => 'leasing_bene']);

        $this->say('#menu_richiedi', '#mutuo');

        $this->assertSame('leasing_bene', Conversation::first()->node);
    }

    public function test_si_cambia_da_cosa_dipendono_i_salti(): void
    {
        // la durata ora salta in base alla risposta stessa (non al prodotto): tutte le durate → riepilogo
        $durata = $this->node('durata');
        $durata->jumps()->get()->each->delete();
        $durata->jumps()->create(['when_value' => '*', 'go_to' => 'riepilogo', 'sort_order' => 1]);
        $durata->update(['jump_by' => 'answer']);

        $this->say('#menu_richiedi', '#personale', '#imp_5k', '#m24');

        $this->assertSame('riepilogo', Conversation::first()->node);
        $this->assertSame('riepilogo', app(FlowRepository::class)->node('richiesta', 'durata')['next']);
    }

    public function test_i_salti_dei_controlli_automatici_si_seguono_per_esito(): void
    {
        $verifica = $this->node('verifica_cf', 'perfezionamento');

        $this->assertSame([['ok', 'luogo_nascita'], ['mismatch', 'conferma_cf']], $this->jumps($verifica));
        $this->assertSame(['ok' => 'luogo_nascita', 'mismatch' => 'conferma_cf'], app(FlowRepository::class)->node('perfezionamento', 'verifica_cf')['next']);
    }

    public function test_un_salto_cancellato_o_modificato_invalida_la_lettura(): void
    {
        $jump = $this->node('importo')->jumps()->first();
        $this->assertSame('durata', app(FlowRepository::class)->node('richiesta', 'importo')['next']);

        $jump->update(['go_to' => 'riepilogo']);
        $this->assertSame('riepilogo', app(FlowRepository::class)->node('richiesta', 'importo')['next']);

        $jump->delete();
        $this->assertArrayNotHasKey('next', app(FlowRepository::class)->node('richiesta', 'importo'));
    }
}
