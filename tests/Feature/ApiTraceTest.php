<?php

namespace Tests\Feature;

use App\Jobs\SendApiTrace;
use App\Services\Bpm\ApiTrace;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ApiTraceTest extends TestCase
{
    private function configure(bool $trace = true): void
    {
        config(['services.bpm.url' => 'https://bpm.example.com', 'services.bpm.key' => 'chiave-bpm', 'services.bpm.trace' => $trace]);
    }

    public function test_outgoing_http_calls_are_reported_as_metadata_only_and_without_the_query(): void
    {
        $this->configure();
        Queue::fake();
        Http::fake(['graph.facebook.com/*' => Http::response(['secret' => 'contenuto'], 200)]);

        Http::withToken('token-segreto')->post('https://graph.facebook.com/v20.0/1/messages?access_token=x', ['text' => 'ciao']);

        Queue::assertPushed(SendApiTrace::class, function (SendApiTrace $job): bool {
            return $job->call['origin'] === 'unicoagent' && $job->call['method'] === 'POST'
                && $job->call['url'] === 'https://graph.facebook.com/v20.0/1/messages' && $job->call['status_code'] === 200
                && ! str_contains(json_encode($job->call), 'ciao') && ! str_contains(json_encode($job->call), 'segreto');
        });
    }

    public function test_calls_to_unicobpm_and_disabled_tracing_are_not_reported(): void
    {
        $this->configure();
        Queue::fake();
        Http::fake();

        Http::post('https://bpm.example.com/api/events', []);
        Queue::assertNothingPushed();

        $this->configure(trace: false);
        Http::post('https://graph.facebook.com/v20.0/1', []);
        Queue::assertNothingPushed();
    }

    public function test_the_job_posts_the_call_to_unicobpm_with_the_shared_key(): void
    {
        $this->configure();
        Http::fake(['https://bpm.example.com/*' => Http::response(['id' => 1], 201)]);

        (new SendApiTrace(['origin' => 'unicoagent', 'method' => 'GET', 'url' => 'https://x.test/a', 'correlation_id' => 'abc']))->handle();

        Http::assertSent(fn ($request) => $request->url() === 'https://bpm.example.com/api/api-logs' && $request->header('X-Api-Key') === ['chiave-bpm'] && $request['origin'] === 'unicoagent');
    }

    public function test_a_failed_http_call_is_reported_with_the_error_type(): void
    {
        $this->configure();
        Queue::fake();
        Http::fake(fn () => throw new ConnectionException('boom'));

        try {
            Http::get('https://graph.facebook.com/v20.0/1');
        } catch (\Throwable) {
        }

        Queue::assertPushed(SendApiTrace::class, fn (SendApiTrace $job) => $job->call['error'] === 'ConnectionException' && $job->call['status_code'] === null);
        $this->assertTrue(ApiTrace::isEnabled());
    }
}
