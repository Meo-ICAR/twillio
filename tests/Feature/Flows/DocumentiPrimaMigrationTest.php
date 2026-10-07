<?php

namespace Tests\Feature\Flows;

use App\Models\Flow;
use App\Models\FlowNode;
use App\Services\Flows\FlowCloner;
use App\Services\Flows\FlowRepository;
use Database\Seeders\FlowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** La migrazione porta un albero di perfezionamento già in uso (con i documenti dopo i dati) alla nuova struttura. */
class DocumentiPrimaMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PARAMS = ['analyze', 'ack', 'reask'];

    private function migrate(): void
    {
        (require database_path('migrations/2026_10_07_000020_put_documents_before_data_in_perfezionamento.php'))->up();
        app(FlowRepository::class)->forget();
    }

    private function node(Flow $flow, string $code): FlowNode
    {
        return $flow->nodes()->where('code', $code)->firstOrFail();
    }

    /** Riporta l'albero com'era prima: documenti alla fine, senza attesa né revisione, senza i nuovi parametri. */
    private function ageToOldShape(Flow $flow): void
    {
        $this->node($flow, 'attesa_documenti')->delete();
        $this->node($flow, 'rivedi_dati')->delete();

        foreach (['informativa' => 'codice_fiscale', 'doc_reddito' => 'riepilogo_p', 'data_assunzione' => 'doc_identita', 'partita_iva' => 'doc_identita'] as $code => $old) {
            $this->node($flow, $code)->jumps()->where('when_value', '*')->update(['go_to' => $old]);
        }

        foreach ($flow->nodes()->get() as $node) {
            $params = array_diff_key($node->params ?? [], array_flip([...self::NEW_PARAMS, 'skip_if']));
            if ($node->code === 'informativa') {
                $params['skip_if'] = 'privacy_received';
            }
            if (str_starts_with($node->code, 'cognome') || in_array($node->code, ['nome', 'luogo_nascita'], true)) {
                $params['skip_if'] = 'filled:'.$node->code;
            }
            $node->update(['params' => $params ?: null]);
        }

        $docs = ['doc_identita', 'doc_cf', 'doc_reddito'];
        $order = $flow->nodes()->orderBy('sort_order')->pluck('code')->reject(fn ($c) => in_array($c, $docs, true))->values();
        $order->splice($order->search('riepilogo_p'), 0, $docs);
        $order->each(fn ($code, $i) => $flow->nodes()->where('code', $code)->update(['sort_order' => $i + 1]));
    }

    private function assertMatchesConfig(bool $test): void
    {
        $repo = app(FlowRepository::class);
        $repo->setTest($test);
        $expected = config('finanziamento.flows.perfezionamento');
        $actual = $repo->flow('perfezionamento');
        $repo->setTest(false);

        $this->assertSame(array_keys($expected['nodes']), array_keys($actual['nodes']), 'ordine dei nodi');
        $this->assertEquals($expected, $actual);
    }

    public function test_un_albero_vecchio_diventa_uguale_alla_configurazione(): void
    {
        $this->seed(FlowSeeder::class);
        $production = Flow::where('code', 'perfezionamento')->where('is_test', false)->firstOrFail();
        $this->ageToOldShape($production);
        app(FlowRepository::class)->forget();
        $this->assertNotEquals(config('finanziamento.flows.perfezionamento'), app(FlowRepository::class)->flow('perfezionamento'));

        $this->migrate();

        $this->assertMatchesConfig(test: false);
    }

    public function test_vale_anche_per_la_copia_di_prova(): void
    {
        $this->seed(FlowSeeder::class);
        $production = Flow::where('code', 'perfezionamento')->where('is_test', false)->firstOrFail();
        $this->ageToOldShape($production);
        app(FlowCloner::class)->createTestCopy($production);

        $this->migrate();

        $this->assertMatchesConfig(test: false);
        $this->assertMatchesConfig(test: true);
    }

    public function test_e_ripetibile(): void
    {
        $this->seed(FlowSeeder::class);
        $this->ageToOldShape(Flow::where('code', 'perfezionamento')->where('is_test', false)->firstOrFail());

        $this->migrate();
        $this->migrate();

        $this->assertMatchesConfig(test: false);
        $this->assertSame(1, FlowNode::where('code', 'attesa_documenti')->count());
        $this->assertSame(1, FlowNode::where('code', 'rivedi_dati')->count());
    }

    public function test_le_modifiche_fatte_a_mano_si_conservano(): void
    {
        $this->seed(FlowSeeder::class);
        $production = Flow::where('code', 'perfezionamento')->where('is_test', false)->firstOrFail();
        $this->ageToOldShape($production);
        $this->node($production, 'residenza')->update(['prompt' => 'Dove abita il cliente?']);
        $this->node($production, 'riepilogo_documenti')->update(['prompt' => 'Testo mio {documenti}']);
        // L'utente ha già indirizzato il reddito altrove: la migrazione non lo tocca.
        $this->node($production, 'doc_reddito')->jumps()->where('when_value', '*')->update(['go_to' => 'iban']);
        $this->node($production, 'informativa')->update(['params' => ['kind' => 'informativa', 'skip_if' => 'privacy_received', 'ack' => false]]);

        $this->migrate();

        $this->assertSame('Dove abita il cliente?', $this->node($production, 'residenza')->prompt);
        $this->assertSame('Testo mio {documenti}', $this->node($production, 'riepilogo_documenti')->prompt);
        $this->assertSame('iban', $this->node($production, 'doc_reddito')->jumps()->where('when_value', '*')->value('go_to'));
        $this->assertFalse($this->node($production, 'informativa')->params['ack'], 'un parametro già impostato non si cambia');
        $this->assertTrue($this->node($production, 'informativa')->params['analyze'], 'quelli mancanti si aggiungono');
    }

    public function test_senza_il_percorso_non_fa_nulla(): void
    {
        $this->migrate();

        $this->assertSame(0, Flow::count());
    }
}
