<?php

namespace Tests\Feature\TestMode;

use App\Models\Flow;
use App\Models\FlowNode;
use App\Services\Flows\FlowCloner;
use App\Services\Flows\FlowRepository;
use Database\Seeders\FlowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowTestCopyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FlowSeeder::class);
    }

    private function prod(string $code = 'richiesta'): Flow
    {
        return Flow::where('code', $code)->where('is_test', false)->firstOrFail();
    }

    private function copyOf(string $code = 'richiesta'): ?Flow
    {
        return Flow::where('code', $code)->where('is_test', true)->first();
    }

    private function repo(): FlowRepository
    {
        return app(FlowRepository::class);
    }

    public function test_lo_stesso_codice_puo_esistere_in_produzione_e_in_prova(): void
    {
        app(FlowCloner::class)->createTestCopy($this->prod());

        $this->assertSame(2, Flow::where('code', 'richiesta')->count());
        $this->assertFalse($this->prod()->is_test);
        $this->assertTrue($this->copyOf()->is_test);
    }

    public function test_la_copia_di_prova_e_identica_alla_produzione(): void
    {
        app(FlowCloner::class)->createTestCopy($this->prod());

        $this->repo()->setTest(true);
        $copy = $this->repo()->all();
        $this->repo()->setTest(false);

        $this->assertEquals($this->repo()->flow('richiesta'), $copy['richiesta']);
        $this->assertSame(FlowNode::where('flow_id', $this->prod()->id)->count(), FlowNode::where('flow_id', $this->copyOf()->id)->count());
        $this->assertSame(
            $this->prod()->nodes()->with('jumps')->get()->flatMap->jumps->count(),
            $this->copyOf()->nodes()->with('jumps')->get()->flatMap->jumps->count()
        );
    }

    public function test_senza_copia_di_prova_la_modalita_prova_usa_la_produzione(): void
    {
        $this->repo()->setTest(true);

        $this->assertEquals(config('finanziamento.flows.richiesta.start'), $this->repo()->flow('richiesta')['start']);
        $this->assertSame($this->repo()->node('richiesta', 'importo')['prompt'], config('finanziamento.flows.richiesta.nodes.importo.prompt'));
    }

    public function test_le_modifiche_alla_copia_di_prova_non_toccano_la_produzione(): void
    {
        app(FlowCloner::class)->createTestCopy($this->prod());
        $this->copyOf()->nodes()->where('code', 'importo')->first()->update(['prompt' => 'PROVA: quanto?']);
        $this->copyOf()->update(['header' => 'INTESTAZIONE DI PROVA']);

        $this->assertStringNotContainsString('PROVA', $this->repo()->node('richiesta', 'importo')['prompt']);
        $this->assertArrayNotHasKey('header', $this->repo()->flow('richiesta'));

        $this->repo()->setTest(true);
        $this->assertSame('PROVA: quanto?', $this->repo()->node('richiesta', 'importo')['prompt']);
        $this->assertSame('INTESTAZIONE DI PROVA', $this->repo()->flow('richiesta')['header']);
    }

    public function test_una_copia_di_prova_disattivata_non_si_usa(): void
    {
        app(FlowCloner::class)->createTestCopy($this->prod());
        $this->copyOf()->nodes()->where('code', 'importo')->first()->update(['prompt' => 'PROVA']);
        $this->copyOf()->update(['is_active' => false]);

        $this->repo()->setTest(true);

        $this->assertStringNotContainsString('PROVA', $this->repo()->node('richiesta', 'importo')['prompt']);
    }

    public function test_si_sa_quali_percorsi_hanno_una_copia_di_prova(): void
    {
        $this->assertSame([], $this->repo()->testFlowCodes());

        app(FlowCloner::class)->createTestCopy($this->prod('richiesta'));
        app(FlowCloner::class)->createTestCopy($this->prod('documenti'));

        $this->assertEqualsCanonicalizing(['richiesta', 'documenti'], $this->repo()->testFlowCodes());
    }

    public function test_non_si_crea_una_seconda_copia_ma_si_puo_ricreare_dalla_produzione(): void
    {
        $cloner = app(FlowCloner::class);
        $cloner->createTestCopy($this->prod());
        $this->copyOf()->nodes()->where('code', 'importo')->first()->update(['prompt' => 'MODIFICATA']);

        try {
            $cloner->createTestCopy($this->prod());
            $this->fail('una seconda copia deve essere rifiutata');
        } catch (\DomainException) {
            $this->assertSame(1, Flow::where('code', 'richiesta')->where('is_test', true)->count());
        }

        $cloner->createTestCopy($this->prod(), replace: true);

        $this->assertSame(1, Flow::where('code', 'richiesta')->where('is_test', true)->count());
        $this->assertSame(config('finanziamento.flows.richiesta.nodes.importo.prompt'), $this->copyOf()->nodes()->where('code', 'importo')->value('prompt'));
    }

    public function test_pubblicare_porta_la_prova_in_produzione(): void
    {
        $cloner = app(FlowCloner::class);
        $cloner->createTestCopy($this->prod());
        $copy = $this->copyOf();
        $copy->update(['header' => 'Novità']);
        $importo = $copy->nodes()->where('code', 'importo')->first();
        $importo->update(['prompt' => 'Quanto ti serve, esattamente?', 'skippable' => true]);
        $importo->options()->where('code', 'imp_5k')->first()->update(['title' => 'Fino a 5 mila']);
        $importo->jumps()->first()->update(['go_to' => 'riepilogo']);
        $prodId = $this->prod()->id;

        $cloner->publish($copy->fresh());

        $this->assertSame($prodId, $this->prod()->id, 'la riga di produzione è la stessa');
        $def = $this->repo()->node('richiesta', 'importo');
        $this->assertSame('Quanto ti serve, esattamente?', $def['prompt']);
        $this->assertTrue($def['skippable']);
        $this->assertSame('Fino a 5 mila', $def['options']['imp_5k']);
        $this->assertSame('riepilogo', $def['next']);
        $this->assertSame('Novità', $this->repo()->flow('richiesta')['header']);
        $this->assertFalse($this->prod()->is_test);
        $this->assertSame(FlowNode::where('flow_id', $copy->id)->count(), FlowNode::where('flow_id', $prodId)->count());
    }

    public function test_non_si_pubblica_una_prova_con_errori(): void
    {
        $cloner = app(FlowCloner::class);
        $cloner->createTestCopy($this->prod());
        $copy = $this->copyOf();
        $copy->nodes()->where('code', 'importo')->first()->jumps()->update(['go_to' => 'domanda_fantasma']);
        $before = $this->repo()->node('richiesta', 'importo')['next'];

        try {
            $cloner->publish($copy->fresh());
            $this->fail('deve rifiutare');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('domanda_fantasma', $e->getMessage());
        }

        $this->assertSame($before, $this->repo()->node('richiesta', 'importo')['next'], 'la produzione resta com\'era');
    }

    public function test_si_pubblica_solo_una_copia_di_prova(): void
    {
        $this->expectException(\DomainException::class);

        app(FlowCloner::class)->publish($this->prod());
    }
}
