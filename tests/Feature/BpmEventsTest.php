<?php

namespace Tests\Feature;

use App\Jobs\SendBpmEvent;
use App\Models\LoanRequest;
use App\Services\Bpm\BpmEvents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BpmEventsTest extends TestCase
{
    use RefreshDatabase;

    private function loan(array $override = []): LoanRequest
    {
        return LoanRequest::create($override + [
            'code' => 'FIN-2026-0300', 'agent_wa_number' => '393331112222', 'product' => 'personale', 'status' => 'perfezionata',
            'answers' => ['prodotto' => 'personale'], 'perfected_at' => now(), 'crm_lead_id' => 'WA-FIN-2026-0300',
        ]);
    }

    private function configure(): void
    {
        config(['services.bpm.url' => 'https://bpm.example.com', 'services.bpm.key' => 'chiave-bpm']);
    }

    public function test_a_perfected_practice_queues_the_event_with_a_fixed_id(): void
    {
        $this->configure();
        Queue::fake();

        BpmEvents::dispatchPerfected($this->loan());

        Queue::assertPushed(SendBpmEvent::class, function (SendBpmEvent $job): bool {
            return $job->event['event_id'] === 'pratica.perfezionata:FIN-2026-0300'
                && $job->event['event'] === 'pratica.perfezionata' && $job->event['source'] === 'unicoagent'
                && $job->event['subject_ref'] === 'FIN-2026-0300'
                && $job->event['data']['codice_pratica_crm'] === 'WA-FIN-2026-0300' && $job->event['data']['prodotto'] === 'personale';
        });
    }

    public function test_the_event_carries_the_total_ai_usage_of_the_documents(): void
    {
        $loan = $this->loan();
        $loan->attachments()->create(['kind' => 'documento', 'path' => 'a', 'mime' => 'image/jpeg', 'received_at' => now(), 'ai_model' => 'claude-opus-5-5', 'ai_input_tokens' => 1000, 'ai_output_tokens' => 200, 'ai_cost' => 0.008]);
        $loan->attachments()->create(['kind' => 'documento', 'path' => 'b', 'mime' => 'image/jpeg', 'received_at' => now(), 'ai_model' => 'claude-opus-5-5', 'ai_input_tokens' => 500, 'ai_output_tokens' => 100, 'ai_cost' => 0.004]);
        $loan->attachments()->create(['kind' => 'documento', 'path' => 'c', 'mime' => 'image/jpeg', 'received_at' => now()]);

        $this->assertSame(
            ['model' => 'claude-opus-5-5', 'input_tokens' => 1500, 'output_tokens' => 300, 'cost' => 0.012, 'readings' => 2],
            BpmEvents::perfected($loan)['data']['ai_usage'],
        );
    }

    public function test_nothing_is_sent_without_configuration_or_for_test_practices(): void
    {
        Queue::fake();

        BpmEvents::dispatchPerfected($this->loan());
        $this->configure();
        BpmEvents::dispatchPerfected($this->loan(['code' => 'FIN-T', 'is_test' => true]));

        Queue::assertNothingPushed();
    }

    public function test_the_job_posts_the_event_with_the_key_and_accepts_any_2xx(): void
    {
        $this->configure();
        Http::fake(['https://bpm.example.com/*' => Http::response(['status' => 'processed'], 202)]);

        (new SendBpmEvent(BpmEvents::perfected($this->loan())))->handle();

        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === 'https://bpm.example.com/api/events'
            && $request->header('X-Api-Key') === ['chiave-bpm'] && $request['event_id'] === 'pratica.perfezionata:FIN-2026-0300');
    }

    public function test_a_server_error_or_a_network_error_is_retried_and_a_client_error_is_final(): void
    {
        $this->configure();
        $job = new SendBpmEvent(BpmEvents::perfected($this->loan()));

        Http::fake(['*' => Http::response('boom', 503)]);
        try {
            $job->handle();
            $this->fail('Doveva lanciare per ritentare.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('503', $e->getMessage());
        }

        Http::fake(fn () => throw new ConnectionException('timeout'));
        $this->expectException(\RuntimeException::class);
        $job->handle();
    }

    public function test_a_rejected_event_fails_the_job_without_retrying(): void
    {
        $this->configure();
        Http::fake(['*' => Http::response(['message' => 'no'], 422)]);
        $job = (new SendBpmEvent(BpmEvents::perfected($this->loan())))->withFakeQueueInteractions();

        $job->handle();

        $job->assertFailed();
    }
}
