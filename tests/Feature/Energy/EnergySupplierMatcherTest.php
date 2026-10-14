<?php

namespace Tests\Feature\Energy;

use App\Models\EnergySupplier;
use App\Services\Energy\EnergySupplierMatcher;
use Database\Seeders\EnergySupplierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnergySupplierMatcherTest extends TestCase
{
    use RefreshDatabase;

    private function matcher(): EnergySupplierMatcher
    {
        return app(EnergySupplierMatcher::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(EnergySupplierSeeder::class);
    }

    public function test_il_seeder_importa_i_venditori_arera_senza_duplicare(): void
    {
        $count = EnergySupplier::count();
        $this->assertGreaterThan(800, $count);

        $this->seed(EnergySupplierSeeder::class);

        $this->assertSame($count, EnergySupplier::count());
        $enel = EnergySupplier::where('vat', '06655971007')->first();
        $this->assertTrue($enel->sells_electricity && $enel->sells_gas);
    }

    public function test_il_nome_completo_e_riconosciuto_senza_badare_a_forma_giuridica_maiuscole_e_punti(): void
    {
        foreach (['Enel Energia', 'ENEL ENERGIA S.P.A.', 'enel energia spa', 'Enel  Energia S.p.A'] as $input) {
            $r = $this->matcher()->match($input);
            $this->assertSame('found', $r['status'], $input);
            $this->assertSame('06655971007', $r['supplier']->vat, $input);
        }
    }

    public function test_un_nome_parziale_con_un_solo_candidato_e_accettato(): void
    {
        $r = $this->matcher()->match('Sorgenia');

        $this->assertSame('found', $r['status']);
        $this->assertSame('SORGENIA S.P.A.', $r['supplier']->name);
    }

    public function test_un_nome_ambiguo_propone_i_candidati(): void
    {
        $r = $this->matcher()->match('Enel');

        $this->assertSame('many', $r['status']);
        $this->assertContains('ENEL ENERGIA S.P.A.', collect($r['candidates'])->pluck('name')->all());
        $this->assertLessThanOrEqual(5, count($r['candidates']));
    }

    public function test_un_nome_sconosciuto_o_troppo_corto_non_trova_nulla(): void
    {
        $this->assertSame('none', $this->matcher()->match('Zzxqwv Energie')['status']);
        $this->assertSame('none', $this->matcher()->match('en')['status']);
        $this->assertSame('none', $this->matcher()->match('   ')['status']);
    }

    public function test_altro_e_una_via_d_uscita_per_i_fornitori_non_in_elenco(): void
    {
        $this->assertSame('other', $this->matcher()->match(' Altro ')['status']);
    }
}
