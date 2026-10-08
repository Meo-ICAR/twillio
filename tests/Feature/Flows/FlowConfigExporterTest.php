<?php

namespace Tests\Feature\Flows;

use App\Models\Flow;
use App\Models\FlowCheck;
use App\Models\FlowNode;
use App\Services\Checks\IbanCheck;
use App\Services\Flows\FlowCloner;
use App\Services\Flows\FlowConfigExporter;
use App\Services\Flows\FlowImporter;
use App\Services\Flows\FlowRepository;
use Database\Seeders\FlowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowConfigExporterTest extends TestCase
{
    use RefreshDatabase;

    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FlowSeeder::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function load(string $source): array
    {
        $file = tempnam(sys_get_temp_dir(), 'flows').'.php';
        file_put_contents($file, $source);
        $this->files[] = $file;

        return require $file;
    }

    private function export(): string
    {
        return app(FlowConfigExporter::class)->export();
    }

    private function node(string $flow, string $code): FlowNode
    {
        return FlowNode::where('flow_id', Flow::where('code', $flow)->where('is_test', false)->value('id'))->where('code', $code)->firstOrFail();
    }

    public function test_produce_un_file_php_valido_con_checks_menu_e_percorsi(): void
    {
        $source = $this->export();

        $this->assertStringStartsWith('<?php', $source);
        $this->assertStringContainsString('Generato', $source);
        $loaded = $this->load($source);
        $this->assertSame(['checks', 'menu', 'flows'], array_keys($loaded));
        $this->assertSame(['richiesta', 'perfezionamento', 'documenti'], array_keys($loaded['flows']));
    }

    public function test_i_percorsi_esportati_sono_quelli_del_database(): void
    {
        $this->assertEquals(app(FlowRepository::class)->all(), $this->load($this->export())['flows']);
    }

    public function test_riporta_le_modifiche_fatte_dal_pannello(): void
    {
        $importo = $this->node('richiesta', 'importo');
        $importo->update(['prompt' => 'Quanto ti serve, "esattamente"?', 'skippable' => true]);
        $importo->options()->where('code', 'imp_5k')->update(['title' => 'Fino a 5 mila']);
        $importo->jumps()->update(['go_to' => 'riepilogo']);
        Flow::where('code', 'richiesta')->where('is_test', false)->update(['header' => "Riga uno\nRiga due"]);

        $flows = $this->load($this->export())['flows'];

        $this->assertSame('Quanto ti serve, "esattamente"?', $flows['richiesta']['nodes']['importo']['prompt']);
        $this->assertTrue($flows['richiesta']['nodes']['importo']['skippable']);
        $this->assertSame('Fino a 5 mila', $flows['richiesta']['nodes']['importo']['options']['imp_5k']);
        $this->assertSame('riepilogo', $flows['richiesta']['nodes']['importo']['next']);
        $this->assertSame("Riga uno\nRiga due", $flows['richiesta']['header']);
    }

    public function test_i_caratteri_speciali_sopravvivono(): void
    {
        $tricky = 'Il "cliente" paga $5 e l\'agente scrive \\ e {codice} ✅ à é — «ok»'."\nSecondo rigo\ttab";
        $this->node('richiesta', 'importo')->update(['prompt' => $tricky]);

        $this->assertSame($tricky, $this->load($this->export())['flows']['richiesta']['nodes']['importo']['prompt']);
    }

    public function test_ricaricare_il_file_ricrea_lo_stesso_albero_in_un_database_vuoto(): void
    {
        $this->node('richiesta', 'importo')->update(['prompt' => 'Modificata a mano', 'skippable' => true]);
        $this->node('perfezionamento', 'residenza')->update(['skippable' => true]);
        $before = app(FlowRepository::class)->all();
        $exported = $this->load($this->export());

        Flow::all()->each->delete();
        config(['finanziamento' => $exported]);
        app(FlowRepository::class)->forget();
        app(FlowImporter::class)->import();

        $this->assertEquals($before, app(FlowRepository::class)->all());
        $this->assertSame(3, Flow::count());
    }

    public function test_i_controlli_si_esportano_come_classi_e_solo_quelli_attivi(): void
    {
        FlowCheck::where('code', 'maggiorenne')->update(['is_active' => false]);

        $source = $this->export();
        $checks = $this->load($source)['checks'];

        $this->assertArrayNotHasKey('maggiorenne', $checks);
        $this->assertSame(IbanCheck::class, $checks['iban']);
        $this->assertStringContainsString('\\App\\Services\\Checks\\IbanCheck::class', $source, 'scritto come ::class, non come testo');
    }

    public function test_le_copie_di_prova_non_si_esportano(): void
    {
        app(FlowCloner::class)->createTestCopy(Flow::where('code', 'richiesta')->where('is_test', false)->first());
        Flow::where('code', 'richiesta')->where('is_test', true)->first()->nodes()->where('code', 'importo')->first()->update(['prompt' => 'SOLO PROVA']);

        $this->assertStringNotContainsString('SOLO PROVA', $this->export());
    }

    public function test_il_menu_resta_quello_della_configurazione(): void
    {
        $this->assertSame(config('finanziamento.menu'), $this->load($this->export())['menu']);
    }

    public function test_e_leggibile_una_domanda_per_blocco_e_con_le_opzioni_una_per_riga(): void
    {
        $source = $this->export();

        $this->assertStringContainsString("'importo' => [", $source);
        $this->assertStringContainsString("'imp_5k' => 'Fino a 5.000 €',", $source);
        $this->assertStringNotContainsString('array (', $source, 'sintassi corta, non var_export');
    }

    public function test_la_data_nell_intestazione_si_puo_fissare_per_avere_un_file_ripetibile(): void
    {
        $at = new \DateTimeImmutable('2026-10-07 12:00:00');

        $a = app(FlowConfigExporter::class)->export($at);
        $b = app(FlowConfigExporter::class)->export($at);

        $this->assertSame($a, $b);
        $this->assertStringContainsString('07/10/2026 12:00', $a);
    }
}
