<?php

namespace Tests\Feature\Finanziamento;

use App\Models\Company;
use App\Models\Fornitore;
use App\Models\LoanRequest;
use App\Services\Loans\LoanEstimator;
use App\Services\Loans\RandomLoanEstimator;

class ImportiOttenibiliTest extends ConversationTestCase
{
    private const FLOW = ['#menu_richiedi', '#personale', '#imp_5k', '#m24', '#dip_priv', '#det', '#anz_1', '#red_1000', '#no', '#no', '#conferma'];

    private function fixedEstimator(): void
    {
        $this->app->instance(LoanEstimator::class, new class implements LoanEstimator
        {
            public function estimate(LoanRequest $loan): array
            {
                return ['min' => 5000, 'max' => 12500];
            }
        });
    }

    public function test_il_simulatore_da_un_minimo_inferiore_al_massimo(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $r = (new RandomLoanEstimator)->estimate(new LoanRequest);
            $this->assertLessThan($r['max'], $r['min']);
            $this->assertGreaterThan(0, $r['min']);
        }
        $this->assertInstanceOf(RandomLoanEstimator::class, app(LoanEstimator::class));
    }

    public function test_un_produttore_riceve_l_importo_minimo_e_massimo(): void
    {
        $this->fixedEstimator();
        Fornitore::create(['name' => 'Agenzia Bianchi', 'tel' => '+39 333 111 2222', 'is_active' => true]);

        $body = $this->bodies($this->say(...self::FLOW));

        $this->assertStringContainsString('Codice pratica', $body);
        $this->assertStringContainsString('da *5.000 €* a *12.500 €*', $body);
        $this->assertSame(1, Fornitore::count());
    }

    public function test_un_numero_sconosciuto_diventa_segnalatore_occasionale_e_non_riceve_importi(): void
    {
        $this->fixedEstimator();
        Company::create(['name' => 'Hassisto Srl', 'customer_care_phone' => '+39 02 1234567', 'customer_care_email' => 'care@hassisto.com']);

        $body = $this->bodies($this->say(...self::FLOW));

        $this->assertStringContainsString('Codice pratica', $body);
        $this->assertStringNotContainsString('Importo ottenibile', $body);
        $this->assertStringNotContainsString('5.000', $body);
        $this->assertStringContainsString('contatta telefonicamente il customer care di Hassisto Srl', $body);
        $this->assertStringContainsString('+39 02 1234567', $body);
        $this->assertStringContainsString('care@hassisto.com', $body);

        $f = Fornitore::sole();
        $this->assertSame($this->agent, $f->tel);
        $this->assertFalse($f->is_active);
        $this->assertSame('Segnalatore occasionale', $f->type);
    }

    public function test_il_segnalatore_occasionale_non_si_duplica_alla_seconda_richiesta(): void
    {
        $this->say(...self::FLOW);
        $this->say(...self::FLOW);

        $this->assertSame(1, Fornitore::count());
    }

    public function test_un_produttore_non_attivo_gia_in_tabella_e_trattato_da_occasionale_senza_duplicarlo(): void
    {
        Fornitore::create(['name' => 'Ex agente', 'tel' => '3331112222', 'is_active' => false]);

        $body = $this->bodies($this->say(...self::FLOW));

        $this->assertStringNotContainsString('Importo ottenibile', $body);
        $this->assertSame(1, Fornitore::count());
    }

    public function test_senza_recapiti_del_customer_care_invita_comunque_a_chiamare(): void
    {
        $body = $this->bodies($this->say(...self::FLOW));

        $this->assertStringContainsString('contatta telefonicamente il customer care', $body);
        $this->assertStringNotContainsString('📞', $body);
    }
}
