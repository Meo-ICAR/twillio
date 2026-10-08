<?php

namespace Tests\Feature\Finanziamento;

use App\Mail\QuoteMail;
use App\Models\Company;
use App\Models\Fornitore;
use App\Models\LoanRequest;
use App\Services\Loans\LoanEstimator;
use App\Services\Loans\MediafacileLoanEstimator;
use App\Services\Loans\QuoteUnavailable;
use Illuminate\Support\Facades\Mail;

class QuoteFallbackTest extends ConversationTestCase
{
    private const FLOW = ['#menu_richiedi', '#personale', '#imp_5k', '#m24', '#eta_40', '#sesso_m', '#dip_priv', '#det', '#anz_1', '#red_1500', '#no', '#no', '#conferma'];

    public function test_se_la_stima_non_e_disponibile_la_richiesta_parte_per_email(): void
    {
        $this->app->instance(LoanEstimator::class, new class implements LoanEstimator
        {
            public function estimate(LoanRequest $loan): array
            {
                throw new QuoteUnavailable('servizio giù');
            }
        });
        Company::create(['name' => 'Hassisto Srl', 'url_preventivatore' => 'https://crm.example.com/ws/offerte']);
        Fornitore::create(['name' => 'Agenzia Bianchi', 'tel' => '+39 333 111 2222', 'is_active' => true]);

        $body = $this->bodies($this->say(...self::FLOW));

        $this->assertStringContainsString('Codice pratica', $body);
        $this->assertStringContainsString('inoltrato la richiesta all\'istruttoria', $body);
        $this->assertStringNotContainsString('Importo ottenibile', $body);
        Mail::assertSent(QuoteMail::class);
    }

    public function test_con_driver_mediafacile_il_vero_stimatore_e_collegato(): void
    {
        config(['finanziamento.quote.driver' => 'mediafacile']);

        $this->assertInstanceOf(MediafacileLoanEstimator::class, app(LoanEstimator::class));
    }
}
