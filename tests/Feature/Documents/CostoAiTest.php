<?php

namespace Tests\Feature\Documents;

use App\Filament\Resources\Attachments\Pages\ListAttachments;
use App\Filament\Resources\LoanRequests\Pages\ViewLoanRequest;
use App\Filament\Resources\LoanRequests\RelationManagers\AttachmentsRelationManager;
use App\Filament\Widgets\WeekWidget;
use App\Models\Attachment;
use App\Models\LoanRequest;
use App\Models\PraticaDocument;
use App\Models\User;
use App\Services\Documents\AiCost;
use App\Services\Documents\DocumentPipeline;
use App\Services\Documents\DocumentReader;
use App\Services\Documents\ReportsUsage;
use Database\Seeders\DocumentCatalogSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class CostoAiTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{model: string, input_tokens: int, output_tokens: int}|null */
    public ?array $usage = ['model' => 'claude-opus-5-5', 'input_tokens' => 1500, 'output_tokens' => 100];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DocumentCatalogSeeder::class);
    }

    private function useReader(bool $reportsUsage = true): void
    {
        $fields = ['kind_detected' => 'identita', 'legible' => true, 'surname' => 'ROSSI', 'name' => 'MARIO', 'fiscal_code' => null, 'document_number' => 'AB1', 'expiry_date' => '01/01/2035'];
        $reader = $reportsUsage
            ? new class($this, $fields) implements DocumentReader, ReportsUsage
            {
                public function __construct(private CostoAiTest $test, private array $fields) {}

                public function enabled(): bool
                {
                    return true;
                }

                public function read(string $kind, string $mime, string $bytes): ?array
                {
                    return $this->fields;
                }

                public function lastUsage(): ?array
                {
                    return $this->test->usage;
                }
            }
        : new class($fields) implements DocumentReader
        {
            public function __construct(private array $fields) {}

            public function enabled(): bool
            {
                return true;
            }

            public function read(string $kind, string $mime, string $bytes): ?array
            {
                return $this->fields;
            }
        };
        $this->app->instance(DocumentReader::class, $reader);
    }

    private function attachment(string $loanCode = 'PM-1007-1000'): Attachment
    {
        $loan = LoanRequest::firstWhere('code', $loanCode) ?? LoanRequest::create(['code' => $loanCode, 'agent_wa_number' => '39', 'product' => 'personale',
            'status' => 'informativa_ricevuta', 'answers' => [], 'privacy_received_at' => now(), 'privacy_verified_at' => now()]);
        $slot = PraticaDocument::populate($loan)->firstWhere('code', 'documento_identita');
        Storage::disk('local')->put("pratiche/x/{$loanCode}.jpg", 'IMG');

        return Attachment::create(['loan_request_id' => $loan->id, 'pratica_document_id' => $slot->id, 'kind' => 'documento_identita',
            'path' => "pratiche/x/{$loanCode}.jpg", 'mime' => 'image/jpeg', 'received_at' => now()]);
    }

    public function test_il_costo_si_calcola_dai_token_e_dai_prezzi_per_milione(): void
    {
        config(['services.anthropic.price_input' => 4.0, 'services.anthropic.price_output' => 20.0]);

        $this->assertSame(24.0, AiCost::usd(1_000_000, 1_000_000));
        $this->assertSame(0.008, AiCost::usd(1500, 100));
        $this->assertSame(0.0, AiCost::usd(0, 0));
    }

    public function test_senza_prezzi_in_configurazione_il_costo_non_c_e(): void
    {
        config(['services.anthropic.price_input' => null]);

        $this->assertNull(AiCost::usd(1000, 1000));
    }

    public function test_i_prezzi_di_partenza_sono_quelli_di_opus_5_5(): void
    {
        $this->assertSame(4.0, (float) config('services.anthropic.price_input'));
        $this->assertSame(20.0, (float) config('services.anthropic.price_output'));
    }

    public function test_la_lettura_scrive_consumo_e_costo_sul_documento(): void
    {
        $this->useReader();
        $a = $this->attachment();

        app(DocumentPipeline::class)->run($a);

        $a->refresh();
        $this->assertSame('claude-opus-5-5', $a->ai_model);
        $this->assertSame(1500, $a->ai_input_tokens);
        $this->assertSame(100, $a->ai_output_tokens);
        $this->assertSame('0.008000', $a->ai_cost);
    }

    public function test_se_lo_stesso_allegato_viene_riletto_il_costo_si_somma(): void
    {
        $this->useReader();
        $a = $this->attachment();

        app(DocumentPipeline::class)->run($a);
        app(DocumentPipeline::class)->run($a->fresh());

        $this->assertSame(3000, $a->fresh()->ai_input_tokens);
        $this->assertSame('0.016000', $a->fresh()->ai_cost);
    }

    public function test_un_lettore_che_non_riporta_il_consumo_lascia_i_campi_vuoti(): void
    {
        $this->useReader(reportsUsage: false);
        $a = $this->attachment();

        app(DocumentPipeline::class)->run($a);

        $this->assertNull($a->fresh()->ai_cost);
        $this->assertNull($a->fresh()->ai_input_tokens);
    }

    public function test_un_documento_in_attesa_dell_informativa_non_costa_nulla(): void
    {
        $this->useReader();
        $a = $this->attachment();
        $a->loanRequest->update(['privacy_verified_at' => null]);

        app(DocumentPipeline::class)->run($a->fresh());

        $this->assertNull($a->fresh()->ai_cost, 'non è stato letto');
    }

    public function test_il_costo_e_salvato_come_numero_interrogabile(): void
    {
        $this->useReader();
        $a = $this->attachment();
        app(DocumentPipeline::class)->run($a);

        $this->assertSame(0.008, (float) DB::table('attachments')->where('id', $a->id)->value('ai_cost'));
        $this->assertEqualsWithDelta(0.008, Attachment::sum('ai_cost'), 0.0000001, 'si può sommare con una query');
    }

    public function test_il_pannello_mostra_il_costo_di_ogni_documento_e_il_totale_della_pratica(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->create());
        $this->useReader();
        $a = $this->attachment();
        app(DocumentPipeline::class)->run($a);
        $b = $this->attachment();
        $b->update(['ai_cost' => '0.012000', 'ai_input_tokens' => 2000, 'ai_output_tokens' => 100, 'ai_model' => 'claude-opus-5-5']);
        $loan = $a->loanRequest;

        $this->assertSame('$ 0,0080', Attachment::formatCost($a->fresh()->ai_cost));
        $this->assertNull(Attachment::formatCost(null));
        Livewire::test(ListAttachments::class)->assertSee('Costo AI')->assertSee('$ 0,0080');
        Livewire::test(AttachmentsRelationManager::class, ['ownerRecord' => $loan, 'pageClass' => ViewLoanRequest::class])->assertSee('$ 0,0080');
        $this->get("/admin/attachments/{$a->id}")->assertOk()->assertSee('1500 token in ingresso');
        $this->get("/admin/loan-requests/{$loan->id}")->assertOk()->assertSee('Costo AI (documenti)')->assertSee('$ 0,0200');
    }

    public function test_la_dashboard_somma_il_costo_degli_ultimi_7_giorni(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->create());
        $a = $this->attachment();
        $a->update(['ai_cost' => '0.050000']);
        $old = $this->attachment('PM-0101-1000');
        $old->update(['ai_cost' => '9.000000', 'received_at' => now()->subDays(30)]);

        Livewire::test(WeekWidget::class)->assertSee('Costo AI (7 giorni)')->assertSee('$ 0,0500')->assertSee('1 letture di documenti');
    }
}
