<?php

namespace Tests\Feature\Flows;

use App\Models\Flow;
use App\Models\FlowNode;
use App\Services\Flows\FlowCloner;
use App\Services\Flows\FlowRepository;
use Database\Seeders\FlowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContattoDirettoMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migrate(): void
    {
        (require database_path('migrations/2026_10_07_000027_add_contatto_diretto_to_perfezionamento.php'))->up();
        app(FlowRepository::class)->forget();
    }

    /** Riporta l'albero com'era: senza il passo, con l'email che va dritta all'IBAN. */
    private function age(Flow $flow): void
    {
        $flow->nodes()->where('code', 'contatto_diretto')->firstOrFail()->delete();
        $flow->nodes()->where('code', 'email')->firstOrFail()->jumps()->where('when_value', '*')->update(['go_to' => 'iban']);
        $flow->nodes()->orderBy('sort_order')->pluck('code')->each(fn ($code, $i) => $flow->nodes()->where('code', $code)->update(['sort_order' => $i + 1]));
    }

    private function production(): Flow
    {
        return Flow::where('code', 'perfezionamento')->where('is_test', false)->firstOrFail();
    }

    public function test_un_albero_vecchio_diventa_uguale_alla_configurazione(): void
    {
        $this->seed(FlowSeeder::class);
        $this->age($this->production());
        app(FlowRepository::class)->forget();
        $this->assertNotEquals(config('finanziamento.flows.perfezionamento'), app(FlowRepository::class)->flow('perfezionamento'));

        $this->migrate();

        $this->assertEquals(config('finanziamento.flows.perfezionamento'), app(FlowRepository::class)->flow('perfezionamento'));
        $this->assertSame(array_keys(config('finanziamento.flows.perfezionamento.nodes')), array_keys(app(FlowRepository::class)->flow('perfezionamento')['nodes']));
    }

    public function test_vale_per_la_copia_di_prova_ed_e_ripetibile(): void
    {
        $this->seed(FlowSeeder::class);
        $this->age($this->production());
        app(FlowCloner::class)->createTestCopy($this->production());

        $this->migrate();
        $this->migrate();

        $repo = app(FlowRepository::class);
        $repo->setTest(true);
        $this->assertEquals(config('finanziamento.flows.perfezionamento'), $repo->flow('perfezionamento'));
        $this->assertSame(2, FlowNode::where('code', 'contatto_diretto')->count(), 'uno per versione, nessun doppione');
    }

    public function test_un_salto_cambiato_a_mano_non_si_tocca(): void
    {
        $this->seed(FlowSeeder::class);
        $flow = $this->production();
        $this->age($flow);
        $flow->nodes()->where('code', 'email')->first()->jumps()->where('when_value', '*')->update(['go_to' => 'telefono']);

        $this->migrate();

        $this->assertSame('telefono', $flow->nodes()->where('code', 'email')->first()->jumps()->where('when_value', '*')->value('go_to'));
    }
}
