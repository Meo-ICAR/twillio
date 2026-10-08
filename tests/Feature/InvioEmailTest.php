<?php

namespace Tests\Feature;

use App\Filament\Resources\LoanRequests\Pages\ListLoanRequests;
use App\Mail\LoanSubmissionMail;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\LoanRequest;
use App\Models\User;
use App\Services\Crm\LoanEmailSender;
use Database\Seeders\DocumentCatalogSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class InvioEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Mail::fake();
        $this->seed(DocumentCatalogSeeder::class);
    }

    private function loan(string $code = 'FIN-2026-0001'): LoanRequest
    {
        $loan = LoanRequest::create(['code' => $code, 'agent_wa_number' => '39', 'product' => 'personale', 'status' => 'perfezionata',
            'answers' => ['prodotto' => 'personale'], 'personal' => ['nome' => 'Mario', 'cognome' => 'Rossi', '_difformita' => ['Il cognome non torna']]]);
        Storage::disk('local')->put("pratiche/{$code}/documento_identita-a.jpg", 'IMG');
        Attachment::create(['loan_request_id' => $loan->id, 'kind' => 'documento_identita', 'path' => "pratiche/{$code}/documento_identita-a.jpg", 'mime' => 'image/jpeg', 'received_at' => now()]);
        Attachment::create(['loan_request_id' => $loan->id, 'kind' => 'reddito', 'path' => "pratiche/{$code}/sparito.pdf", 'mime' => 'application/pdf', 'received_at' => now()]);

        return $loan;
    }

    public function test_manda_dati_e_allegati_alla_casella_dell_istruttoria(): void
    {
        Company::create(['name' => 'Hassisto', 'istruttoria_email' => 'istruttoria@example.com']);
        $loan = $this->loan();

        $this->assertTrue(app(LoanEmailSender::class)->send($loan));

        Mail::assertSent(LoanSubmissionMail::class, function (LoanSubmissionMail $m) {
            $m->assertHasTo('istruttoria@example.com');
            $m->assertSeeInHtml('Pratica FIN-2026-0001');
            $m->assertSeeInHtml('Mario');
            $m->assertSeeInHtml('Il cognome non torna');
            $m->assertSeeInHtml('DOCUMENTI');

            return count($m->attachments()) === 1 && $m->hasSubject('Pratica FIN-2026-0001 · Prestito personale');
        });
        $this->assertNotNull($loan->fresh()->emailed_at);
    }

    public function test_usa_la_casella_di_configurazione_se_manca_quella_dell_azienda(): void
    {
        config(['finanziamento.mail.to' => 'fallback@example.com']);

        $this->assertTrue(app(LoanEmailSender::class)->send($this->loan()));

        Mail::assertSent(LoanSubmissionMail::class, fn ($m) => $m->hasTo('fallback@example.com'));
    }

    public function test_senza_casella_non_parte_nulla(): void
    {
        $loan = $this->loan();

        $this->assertFalse(app(LoanEmailSender::class)->send($loan));

        Mail::assertNothingSent();
        $this->assertNull($loan->fresh()->emailed_at);
    }

    public function test_un_errore_di_posta_non_si_propaga(): void
    {
        config(['finanziamento.mail.to' => 'x@example.com']);
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp giù per Mario'));
        $loan = $this->loan();

        $this->assertFalse(app(LoanEmailSender::class)->send($loan));
        $this->assertNull($loan->fresh()->emailed_at);
    }

    public function test_l_azione_di_gruppo_forza_l_invio_anche_se_gia_inviate(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->create());
        config(['finanziamento.mail.to' => 'x@example.com']);
        $a = $this->loan('FIN-2026-0001');
        $a->update(['emailed_at' => now()->subDay()]);
        $b = $this->loan('FIN-2026-0002');

        Livewire::test(ListLoanRequests::class)->callTableBulkAction('inviaEmail', [$a, $b]);

        Mail::assertSent(LoanSubmissionMail::class, 2);
        $this->assertTrue($a->fresh()->emailed_at->gt(now()->subMinute()));
        $this->assertNotNull($b->fresh()->emailed_at);
    }

    public function test_l_azione_di_gruppo_segnala_se_manca_la_casella(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->create());
        $a = $this->loan();

        Livewire::test(ListLoanRequests::class)->callTableBulkAction('inviaEmail', [$a])->assertNotified('Invio non riuscito per: FIN-2026-0001');

        Mail::assertNothingSent();
    }
}
