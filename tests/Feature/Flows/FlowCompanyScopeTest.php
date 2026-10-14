<?php

namespace Tests\Feature\Flows;

use App\Models\Company;
use App\Models\Flow;
use App\Services\Crm\LoanEmailSender;
use App\Services\Flows\FlowImporter;
use App\Services\Flows\FlowRepository;
use Database\Seeders\FlowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowCompanyScopeTest extends TestCase
{
    use RefreshDatabase;

    private function flowFor(Company $company, string $start): void
    {
        Flow::create(['company_id' => $company->id, 'code' => 'richiesta', 'name' => 'R', 'start' => $start, 'restart' => $start, 'is_active' => true])
            ->nodes()->create(['code' => $start, 'type' => 'message', 'prompt' => "Inizio {$company->name}", 'sort_order' => 1, 'jump_by' => 'answer']);
    }

    public function test_lo_stesso_codice_in_due_company_non_si_mescola(): void
    {
        $a = Company::create(['name' => 'A']);
        $b = Company::create(['name' => 'B']);
        $this->flowFor($a, 'inizio_a');
        $this->flowFor($b, 'inizio_b');
        $repo = app(FlowRepository::class);

        $repo->setCompany($b->id);
        $this->assertSame('inizio_b', $repo->flow('richiesta')['start']);
        $repo->setCompany($a->id);
        $this->assertSame('inizio_a', $repo->flow('richiesta')['start']);
        $repo->setCompany(null);
        $this->assertSame('inizio_a', $repo->flow('richiesta')['start'], 'senza company vale la più vecchia');
    }

    public function test_una_company_senza_percorsi_non_ne_eredita_altri(): void
    {
        $a = Company::create(['name' => 'A']);
        $b = Company::create(['name' => 'B']);
        $this->flowFor($a, 'inizio_a');
        $repo = app(FlowRepository::class);

        $repo->setCompany($b->id);
        $this->assertNull($repo->flow('richiesta'));
    }

    public function test_l_importazione_per_company_non_tocca_i_percorsi_delle_altre(): void
    {
        $a = Company::create(['name' => 'A']);
        $b = Company::create(['name' => 'B']);
        $this->seed(FlowSeeder::class);
        Flow::query()->update(['company_id' => $a->id]);

        app(FlowImporter::class)->import(company: $b);

        $this->assertSame(3, Flow::where('company_id', $a->id)->count());
        $this->assertSame(3, Flow::where('company_id', $b->id)->count());
    }

    public function test_il_destinatario_della_mail_e_quello_della_company_della_pratica(): void
    {
        $a = Company::create(['name' => 'A', 'istruttoria_email' => 'a@example.com']);
        $b = Company::create(['name' => 'B', 'istruttoria_email' => 'b@example.com']);

        $this->assertSame('b@example.com', app(LoanEmailSender::class)->recipient($b));
        $this->assertSame('a@example.com', app(LoanEmailSender::class)->recipient(null));
    }
}
