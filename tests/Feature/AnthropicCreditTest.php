<?php

namespace Tests\Feature;

use App\Services\Documents\AnthropicCredit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnthropicCreditTest extends TestCase
{
    private function configure(): void
    {
        Cache::flush();
        config(['services.anthropic.admin_key' => 'sk-ant-admin01-x', 'services.anthropic.credit_balance' => 50, 'services.anthropic.credit_as_of' => '2026-10-01']);
    }

    public function test_the_balance_is_the_starting_balance_minus_the_cost_report_across_pages(): void
    {
        $this->configure();
        Http::fake(['api.anthropic.com/v1/organizations/cost_report*' => Http::sequence()
            ->push(['data' => [['results' => [['amount' => '250.5'], ['amount' => '100']]]], 'has_more' => true, 'next_page' => 'p2'])
            ->push(['data' => [['results' => [['amount' => '149.5']]]], 'has_more' => false])]);

        $estimate = (new AnthropicCredit)->estimate();

        $this->assertTrue($estimate['ok']);
        $this->assertSame(5.0, $estimate['spent']);
        $this->assertSame(45.0, $estimate['balance']);
        Http::assertSent(fn ($r) => $r->header('x-api-key') === ['sk-ant-admin01-x'] && str_contains(urldecode($r->url()), 'starting_at=2026-10-01T00:00:00Z'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'page=p2'));
    }

    public function test_an_unauthorised_key_or_missing_configuration_gives_no_estimate(): void
    {
        $this->configure();
        Http::fake(['*' => Http::response(['error' => ['message' => 'forbidden']], 403)]);

        $this->assertFalse((new AnthropicCredit)->estimate()['ok']);
        $this->assertStringContainsString('Chiave amministrativa', (new AnthropicCredit)->estimate()['detail']);

        config(['services.anthropic.admin_key' => null]);
        $this->assertFalse((new AnthropicCredit)->isConfigured());
    }
}
