<?php

namespace Tests\Feature;

use App\Models\Fornitore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FornitoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_usa_la_tabella_fornitoris_con_id_uuid(): void
    {
        $f = Fornitore::create(['name' => 'Mario Rossi']);

        $this->assertSame('fornitoris', $f->getTable());
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $f->id);
        $this->assertFalse($f->getIncrementing());
        $this->assertSame($f->id, Fornitore::find($f->id)->id);
    }

    public function test_i_campi_sono_convertiti_nei_tipi_giusti(): void
    {
        $f = Fornitore::create([
            'name' => 'Mario Rossi', 'natoil' => '1980-01-01', 'oam_at' => '2020-05-10', 'ivass_at' => '2021-02-03',
            'stipulated_at' => '2022-01-15', 'is_art108' => 1, 'iscollaboratore' => 0, 'issubfornitore' => 1,
            'anticipo' => '1500.5', 'budget' => '20000', 'employee_roles' => ['Agente', 'Supervisore'],
        ])->fresh();

        $this->assertInstanceOf(Carbon::class, $f->natoil);
        $this->assertSame('1980-01-01', $f->natoil->toDateString());
        $this->assertInstanceOf(Carbon::class, $f->oam_at);
        $this->assertTrue($f->is_active);
        $this->assertTrue($f->is_art108);
        $this->assertFalse($f->iscollaboratore);
        $this->assertTrue($f->issubfornitore);
        $this->assertSame('1500.50', $f->anticipo);
        $this->assertSame(['Agente', 'Supervisore'], $f->employee_roles);
        $this->assertSame('no', $f->supervisor_type);
    }

    public function test_la_cancellazione_e_logica_e_lo_scope_active_esclude_cessati_e_cancellati(): void
    {
        $ok = Fornitore::create(['name' => 'Attivo']);
        Fornitore::create(['name' => 'Disattivo', 'is_active' => false]);
        Fornitore::create(['name' => 'Cancellato'])->delete();

        $this->assertSame([$ok->id], Fornitore::active()->pluck('id')->all());
        $this->assertSame(3, Fornitore::withTrashed()->count());
    }

    public function test_collega_l_utente_del_pannello(): void
    {
        $user = User::factory()->create();
        $f = Fornitore::create(['name' => 'Mario', 'user_id' => $user->id]);

        $this->assertTrue($f->user->is($user));
    }

    public function test_trova_l_agente_dal_numero_whatsapp_qualunque_sia_il_formato_del_telefono(): void
    {
        $f = Fornitore::create(['name' => 'Mario Rossi', 'tel' => '+39 333 111 2222']);

        foreach (['393331112222', '39 333 1112222'] as $wa) {
            $this->assertTrue(Fornitore::findByWhatsApp($wa)->is($f), $wa);
        }
        foreach (['3331112222', '+39 333-111.2222', '0039 333 1112222'] as $tel) {
            $f->update(['tel' => $tel]);
            $this->assertTrue(Fornitore::findByWhatsApp('393331112222')->is($f), $tel);
        }
    }

    public function test_non_trova_numeri_diversi_ne_agenti_cessati_o_senza_telefono(): void
    {
        Fornitore::create(['name' => 'Altro', 'tel' => '3339998888']);
        Fornitore::create(['name' => 'Cessato', 'tel' => '3331112222', 'is_active' => false]);
        Fornitore::create(['name' => 'Senza telefono']);

        $this->assertNull(Fornitore::findByWhatsApp('393331112222'));
        $this->assertNull(Fornitore::findByWhatsApp(''));
    }

    public function test_il_nome_visualizzato_preferisce_la_denominazione(): void
    {
        $this->assertSame('Rossi Mario Srl', Fornitore::make(['name' => 'Rossi Mario Srl', 'nome' => 'Mario'])->display_name);
        $this->assertSame('Mario', Fornitore::make(['nome' => 'Mario'])->display_name);
    }
}
