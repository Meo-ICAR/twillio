<?php

namespace Tests\Feature\Checks;

use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\LoanRequest;
use App\Services\Checks\CheckContext;
use App\Services\Checks\CheckRegistry;
use App\Services\Checks\NodeCheck;
use App\Services\Flows\FlowRepository;
use Tests\Feature\Finanziamento\ConversationTestCase;

class NodeChecksTest extends ConversationTestCase
{
    private function node(string $flow, string $code): FlowNode
    {
        return FlowNode::where('flow_id', Flow::where('code', $flow)->value('id'))->where('code', $code)->firstOrFail();
    }

    private function register(string $name, NodeCheck $check): void
    {
        app(CheckRegistry::class)->extend($name, $check);
    }

    private function attach(string $flow, string $node, array $checks): void
    {
        $this->node($flow, $node)->update(['checks' => $checks]);
    }

    private function yes(): NodeCheck
    {
        return new class implements NodeCheck
        {
            public function label(): string
            {
                return 'Sempre sì';
            }

            public function description(): string
            {
                return 'Prova';
            }

            public function derives(): array
            {
                return ['eco'];
            }

            public function passes(string $value, CheckContext $ctx): bool
            {
                $ctx->set('eco', strtoupper($value));

                return true;
            }
        };
    }

    private function no(): NodeCheck
    {
        return new class implements NodeCheck
        {
            public function label(): string
            {
                return 'Sempre no';
            }

            public function description(): string
            {
                return 'Prova';
            }

            public function derives(): array
            {
                return [];
            }

            public function passes(string $value, CheckContext $ctx): bool
            {
                return $ctx->fail('Questo valore non va bene: '.$value);
            }
        };
    }

    public function test_se_il_controllo_restituisce_true_si_prosegue_e_i_dati_ricavati_vengono_salvati(): void
    {
        $this->register('sempre_si', $this->yes());
        $this->attach('perfezionamento', 'luogo_nascita', ['sempre_si']);
        $this->node('perfezionamento', 'luogo_nascita')->update(['skippable' => false]);
        $this->prepareAt('luogo_nascita');

        $this->say('Tunisi');

        $this->assertSame('residenza', Conversation::first()->node);
        $this->assertSame('TUNISI', Conversation::first()->data['eco']);
        $this->assertSame('Tunisi', Conversation::first()->data['luogo_nascita']);
    }

    public function test_se_restituisce_false_si_ripete_la_domanda_con_il_messaggio_del_controllo(): void
    {
        $this->register('sempre_no', $this->no());
        $this->attach('perfezionamento', 'luogo_nascita', ['sempre_no']);
        $this->prepareAt('luogo_nascita');

        $replies = $this->say('Tunisi');

        $this->assertSame('luogo_nascita', Conversation::first()->node);
        $this->assertArrayNotHasKey('luogo_nascita', Conversation::first()->data);
        $this->assertStringContainsString('Questo valore non va bene: Tunisi', $this->bodies($replies));
        $this->assertStringContainsString('Luogo di nascita', $this->bodies($replies), 'la domanda viene riproposta');
    }

    public function test_senza_un_proprio_messaggio_si_usa_quello_della_domanda(): void
    {
        $this->register('muto', new class implements NodeCheck
        {
            public function label(): string
            {
                return 'Muto';
            }

            public function description(): string
            {
                return '';
            }

            public function derives(): array
            {
                return [];
            }

            public function passes(string $value, CheckContext $ctx): bool
            {
                return false;
            }
        });
        $this->attach('perfezionamento', 'luogo_nascita', ['muto']);
        $node = $this->node('perfezionamento', 'luogo_nascita');
        $node->update(['params' => array_merge($node->params ?? [], ['error' => 'Messaggio della domanda.'])]);
        $this->prepareAt('luogo_nascita');

        $this->assertStringContainsString('Messaggio della domanda.', $this->bodies($this->say('Tunisi')));
    }

    public function test_i_controlli_girano_in_ordine_e_il_primo_che_fallisce_ferma_gli_altri(): void
    {
        $this->register('sempre_si', $this->yes());
        $this->register('sempre_no', $this->no());
        $this->attach('perfezionamento', 'luogo_nascita', ['sempre_no', 'sempre_si']);
        $this->prepareAt('luogo_nascita');

        $this->say('Tunisi');

        $this->assertArrayNotHasKey('eco', Conversation::first()->data, 'il secondo non deve aver ricavato nulla');
    }

    public function test_i_dati_ricavati_si_azzerano_tornando_indietro(): void
    {
        $this->register('sempre_si', $this->yes());
        $this->attach('perfezionamento', 'luogo_nascita', ['sempre_si']);
        $this->prepareAt('luogo_nascita');
        $this->say('Tunisi');
        $this->assertArrayHasKey('eco', Conversation::first()->data);

        $this->say('indietro');

        $this->assertSame('luogo_nascita', Conversation::first()->node);
        $this->assertArrayNotHasKey('eco', Conversation::first()->data);
    }

    public function test_un_controllo_sconosciuto_blocca_con_un_messaggio_chiaro_e_non_lascia_passare_dati_non_verificati(): void
    {
        $this->attach('perfezionamento', 'luogo_nascita', ['inesistente']);
        $this->prepareAt('luogo_nascita');

        $body = $this->bodies($this->say('Tunisi'));

        $this->assertStringContainsString('controllo', strtolower($body));
        $this->assertSame('luogo_nascita', Conversation::first()->node);
        $this->assertArrayNotHasKey('luogo_nascita', Conversation::first()->data);
    }

    public function test_un_controllo_con_parametri_riceve_i_suoi_parametri(): void
    {
        $seen = new \stdClass;
        $this->register('con_parametri', new class($seen) implements NodeCheck
        {
            public function __construct(private \stdClass $seen) {}

            public function label(): string
            {
                return 'Param';
            }

            public function description(): string
            {
                return '';
            }

            public function derives(): array
            {
                return [];
            }

            public function passes(string $value, CheckContext $ctx): bool
            {
                $this->seen->anni = $ctx->param('anni');

                return true;
            }
        });
        $this->attach('perfezionamento', 'luogo_nascita', [['name' => 'con_parametri', 'anni' => 21]]);
        $this->prepareAt('luogo_nascita');

        $this->say('Tunisi');

        $this->assertSame(21, $seen->anni);
        $this->assertSame('residenza', Conversation::first()->node);
    }

    public function test_i_controlli_predefiniti_sono_registrati(): void
    {
        foreach (['codice_fiscale', 'iban', 'maggiorenne'] as $name) {
            $this->assertInstanceOf(NodeCheck::class, app(CheckRegistry::class)->get($name), $name);
            $this->assertNotEmpty(app(CheckRegistry::class)->get($name)->label());
        }
        $this->assertEqualsCanonicalizing(['codice_fiscale', 'iban', 'maggiorenne'], array_keys(app(CheckRegistry::class)->all()));
    }

    public function test_i_controlli_agganciati_alla_configurazione_sono_quelli_di_prima(): void
    {
        $cf = app(FlowRepository::class)->node('perfezionamento', 'codice_fiscale');
        $iban = app(FlowRepository::class)->node('perfezionamento', 'iban');

        $this->assertSame('codice_fiscale', $cf['checks'][0]);
        $this->assertSame('maggiorenne', $cf['checks'][1]['name']);
        $this->assertSame(['iban'], $iban['checks']);
        foreach (['derive', 'derives', 'min_age', 'checksum'] as $legacy) {
            $this->assertArrayNotHasKey($legacy, $cf);
            $this->assertArrayNotHasKey($legacy, $iban);
        }
    }

    /** Porta una conversazione di perfezionamento alla domanda indicata. */
    private function prepareAt(string $node): void
    {
        $loan = LoanRequest::create(['code' => 'FIN-2026-0007', 'agent_wa_number' => $this->agent, 'product' => 'personale', 'status' => 'informativa_ricevuta', 'privacy_received_at' => now(), 'answers' => ['prodotto' => 'personale']]);
        Conversation::create(['wa_number' => $this->agent, 'flow' => 'perfezionamento', 'node' => $node, 'loan_request_id' => $loan->id, 'data' => [], 'history' => []]);
    }
}
