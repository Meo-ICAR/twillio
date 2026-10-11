<?php

namespace Tests\Feature\Flows;

use App\Models\Flow;
use App\Services\Flows\FlowRepository;
use Database\Seeders\FlowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FasceChiuseMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migrate(): void
    {
        (require database_path('migrations/2026_10_08_000003_closed_bands_age_and_sex_in_richiesta.php'))->up();
        app(FlowRepository::class)->forget();
    }

    private function production(): Flow
    {
        return Flow::where('code', 'richiesta')->where('is_test', false)->firstOrFail();
    }

    /** Riporta l'albero come in produzione prima della modifica. */
    private function age(Flow $flow): void
    {
        $flow->nodes()->whereIn('code', ['eta', 'sesso'])->get()->each->delete();
        $flow->nodes()->where('code', 'durata')->firstOrFail()->jumps()->whereIn('when_value', ['personale', 'quinto'])->update(['go_to' => 'lavoro']);
        $flow->nodes()->orderBy('sort_order')->pluck('code')->each(fn ($code, $i) => $flow->nodes()->where('code', $code)->update(['sort_order' => $i + 1]));

        $importo = $flow->nodes()->where('code', 'importo')->firstOrFail();
        $importo->options()->delete();
        foreach (['imp_5k' => 'Fino a 5.000 €', 'imp_10k' => '5.000 - 10.000 €', 'imp_20k' => '10.000 - 20.000 €', 'imp_35k' => '20.000 - 35.000 €', 'imp_oltre' => 'Oltre 35.000 €'] as $code => $title) {
            $importo->options()->create(['code' => $code, 'title' => $title, 'sort_order' => $importo->options()->count() + 1]);
        }
        $reddito = $flow->nodes()->where('code', 'reddito')->firstOrFail();
        $reddito->options()->delete();
        foreach (['red_1000' => 'Fino a 1.000 €', 'red_1500' => '1.000 - 1.500 €', 'red_2000' => '1.500 - 2.000 €', 'red_3000' => '2.000 - 3.000 €', 'red_oltre' => 'Oltre 3.000 €'] as $code => $title) {
            $reddito->options()->create(['code' => $code, 'title' => $title, 'sort_order' => $reddito->options()->count() + 1]);
        }
    }

    public function test_un_albero_vecchio_diventa_uguale_alla_configurazione(): void
    {
        $this->seed(FlowSeeder::class);
        $this->age($this->production());
        app(FlowRepository::class)->forget();
        $this->assertNotEquals(config('finanziamento.flows.richiesta'), app(FlowRepository::class)->flow('richiesta'));

        $this->migrate();

        $this->assertEquals(config('finanziamento.flows.richiesta'), app(FlowRepository::class)->flow('richiesta'));
        $this->assertSame(array_keys(config('finanziamento.flows.richiesta.nodes')), array_keys(app(FlowRepository::class)->flow('richiesta')['nodes']));
    }

    public function test_e_ripetibile_e_non_tocca_le_opzioni_modificate_a_mano(): void
    {
        $this->seed(FlowSeeder::class);
        $flow = $this->production();
        $this->age($flow);
        $flow->nodes()->where('code', 'importo')->first()->options()->where('code', 'imp_5k')->update(['title' => 'Fino a 5 mila']);

        $this->migrate();
        $this->migrate();

        $this->assertSame(1, DB::table('flow_nodes')->where('flow_id', $flow->id)->where('code', 'eta')->count(), 'nessun doppione');
        $this->assertSame('Fino a 5 mila', $flow->nodes()->where('code', 'importo')->first()->options()->where('code', 'imp_5k')->value('title'));
        $this->assertSame('3.000 - 5.000 €', $flow->nodes()->where('code', 'reddito')->first()->options()->where('code', 'red_oltre')->value('title'));
    }
}
