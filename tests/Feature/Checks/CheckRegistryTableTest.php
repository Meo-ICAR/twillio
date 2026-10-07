<?php

namespace Tests\Feature\Checks;

use App\Models\FlowCheck;
use App\Services\Checks\CheckRegistry;
use App\Services\Checks\CodiceFiscaleCheck;
use App\Services\Checks\IbanCheck;
use App\Services\Checks\MaggiorenneCheck;
use App\Services\Checks\NodeCheck;
use Database\Seeders\FlowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckRegistryTableTest extends TestCase
{
    use RefreshDatabase;

    private function registry(): CheckRegistry
    {
        return app(CheckRegistry::class);
    }

    public function test_la_migrazione_registra_i_controlli_predefiniti_nella_tabella(): void
    {
        $this->assertSame(['codice_fiscale', 'iban', 'maggiorenne'], FlowCheck::orderBy('code')->pluck('code')->all());
        $this->assertSame(IbanCheck::class, FlowCheck::where('code', 'iban')->value('class'));
        $this->assertTrue(FlowCheck::where('code', 'iban')->first()->is_active);
    }

    public function test_il_registro_legge_la_tabella(): void
    {
        $this->assertInstanceOf(IbanCheck::class, $this->registry()->get('iban'));
        $this->assertSame(['codice_fiscale', 'iban', 'maggiorenne'], array_keys($this->registry()->all()));
        $this->assertNull($this->registry()->get('non_esiste'));
    }

    public function test_un_controllo_disattivato_non_e_disponibile_ma_la_riga_resta(): void
    {
        FlowCheck::where('code', 'iban')->update(['is_active' => false]);
        $this->registry()->forget();

        $this->assertNull($this->registry()->get('iban'));
        $this->assertArrayNotHasKey('iban', $this->registry()->all());
        $this->assertSame(1, FlowCheck::where('code', 'iban')->count());
    }

    public function test_l_ordine_dell_elenco_segue_sort_order(): void
    {
        FlowCheck::where('code', 'maggiorenne')->update(['sort_order' => 1]);
        FlowCheck::where('code', 'iban')->update(['sort_order' => 2]);
        FlowCheck::where('code', 'codice_fiscale')->update(['sort_order' => 3]);
        $this->registry()->forget();

        $this->assertSame(['maggiorenne', 'iban', 'codice_fiscale'], array_keys($this->registry()->all()));
    }

    public function test_senza_righe_nella_tabella_vale_la_configurazione(): void
    {
        FlowCheck::query()->delete();
        $this->registry()->forget();

        $this->assertSame(['codice_fiscale', 'iban', 'maggiorenne'], array_keys($this->registry()->all()));
        $this->assertInstanceOf(CodiceFiscaleCheck::class, $this->registry()->get('codice_fiscale'));
    }

    public function test_si_trovano_le_classi_dei_controlli_nella_cartella(): void
    {
        $found = $this->registry()->discover();

        $this->assertEqualsCanonicalizing([CodiceFiscaleCheck::class, IbanCheck::class, MaggiorenneCheck::class], $found);
        foreach ($found as $class) {
            $this->assertTrue(is_subclass_of($class, NodeCheck::class), $class);
        }
    }

    public function test_il_codice_si_ricava_dal_nome_della_classe(): void
    {
        $this->assertSame('codice_fiscale', FlowCheck::codeFor(CodiceFiscaleCheck::class));
        $this->assertSame('iban', FlowCheck::codeFor(IbanCheck::class));
        $this->assertSame('maggiorenne', FlowCheck::codeFor(MaggiorenneCheck::class));
    }

    public function test_la_sincronizzazione_aggiunge_solo_i_controlli_mancanti(): void
    {
        FlowCheck::where('code', 'iban')->delete();
        $this->registry()->forget();

        $this->assertSame(1, $this->registry()->sync());
        $this->assertSame(IbanCheck::class, FlowCheck::where('code', 'iban')->value('class'));
        $this->assertSame(0, $this->registry()->sync(), 'ripetibile');
        $this->assertSame(3, FlowCheck::count());
    }

    public function test_la_sincronizzazione_non_riattiva_un_controllo_disattivato_a_mano(): void
    {
        FlowCheck::where('code', 'iban')->update(['is_active' => false]);

        $this->registry()->sync();

        $this->assertFalse(FlowCheck::where('code', 'iban')->first()->is_active);
    }

    public function test_nome_e_descrizione_vengono_dalla_classe(): void
    {
        $row = FlowCheck::where('code', 'maggiorenne')->first();

        $this->assertSame((new MaggiorenneCheck)->label(), $row->label());
        $this->assertSame((new MaggiorenneCheck)->description(), $row->description());
    }

    public function test_si_sa_in_quali_domande_e_usato_un_controllo(): void
    {
        $this->seed(FlowSeeder::class);

        $used = FlowCheck::where('code', 'maggiorenne')->first()->usedBy();
        $this->assertSame(['perfezionamento.codice_fiscale'], $used->all());

        $this->assertSame(['perfezionamento.iban'], FlowCheck::where('code', 'iban')->first()->usedBy()->all());
        FlowCheck::create(['code' => 'prova', 'class' => IbanCheck::class, 'sort_order' => 9]);
        $this->assertSame([], FlowCheck::where('code', 'prova')->first()->usedBy()->all());
    }
}
