<?php

namespace Tests\Feature\Finanziamento;

use App\Models\Conversation;

class FasceChiuseTest extends ConversationTestCase
{
    public function test_le_fasce_di_importo_non_sono_piu_aperte(): void
    {
        $options = $this->say('#menu_richiedi', '#personale')[0]->options;

        $this->assertSame('1.000 - 5.000 €', $options['imp_5k']);
        $this->assertSame('35.000 - 50.000 €', $options['imp_oltre']);
    }

    public function test_dopo_la_durata_si_chiedono_eta_e_sesso_per_quinto_e_personale(): void
    {
        $replies = $this->say('#menu_richiedi', '#quinto', '#imp_20k', '#m60');
        $this->assertSame(['eta_30', 'eta_40', 'eta_50', 'eta_60', 'eta_75'], array_keys(end($replies)->options));

        $replies = $this->say('#eta_40');
        $this->assertSame(['sesso_m', 'sesso_f'], array_keys(end($replies)->options));

        $replies = $this->say('#sesso_f');
        $this->assertArrayHasKey('dip_priv', end($replies)->options, 'poi la situazione lavorativa');
        $this->assertSame('sesso_f', Conversation::first()->data['sesso']);
    }

    public function test_il_sesso_si_puo_saltare(): void
    {
        $replies = $this->say('#menu_richiedi', '#personale', '#imp_5k', '#m24', '#eta_40', 'salta');

        $this->assertArrayHasKey('dip_priv', end($replies)->options);
        $this->assertArrayNotHasKey('sesso', Conversation::first()->data);
    }

    public function test_il_finalizzato_non_chiede_eta_e_sesso(): void
    {
        $replies = $this->say('#menu_richiedi', '#finalizzato', '#imp_10k', '#m36');

        $this->assertArrayHasKey('dip_priv', end($replies)->options);
    }

    public function test_anzianita_e_reddito_hanno_fasce_chiuse(): void
    {
        $this->say('#menu_richiedi', '#personale', '#imp_5k', '#m24', '#eta_40', '#sesso_m', '#dip_priv');
        $anzianita = $this->say('#indet');
        $this->assertSame(['anz_1', 'anz_3', 'anz_10', 'anz_20', 'anz_30', 'anz_40'], array_keys(end($anzianita)->options));

        $reddito = $this->say('#anz_10');
        $this->assertSame(['red_1500', 'red_2000', 'red_3000', 'red_oltre'], array_keys(end($reddito)->options));
        $this->assertSame('3.000 - 5.000 €', end($reddito)->options['red_oltre']);
    }
}
