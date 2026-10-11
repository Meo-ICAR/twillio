<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Consegna un evento a UnicoBPM. Errori di rete, 5xx e 408/425/429 si ritentano con attesa crescente (1, 5, 15 minuti, 1 e 4 ore);
 * gli altri 4xx sono definitivi. Il `event_id` rende il rinvio innocuo. Nei log solo codici, mai il contenuto.
 */
class SendBpmEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 6;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900, 3600, 14400];

    /** @param array<string,mixed> $event */
    public function __construct(public array $event) {}

    public function handle(): void
    {
        try {
            $response = Http::asJson()->timeout(10)->connectTimeout(4)
                ->withHeaders(['X-Api-Key' => (string) config('services.bpm.key'), 'X-Correlation-Id' => (string) ($this->event['event_id'] ?? '')])
                ->post(rtrim((string) config('services.bpm.url'), '/').'/api/events', $this->event);
        } catch (ConnectionException) {
            throw new RuntimeException('UnicoBPM non raggiungibile.');
        }

        if ($response->successful()) {
            return;
        }

        if ($response->clientError() && ! in_array($response->status(), [408, 425, 429], true)) {
            Log::warning('UnicoBPM ha rifiutato l\'evento.', ['event' => $this->event['event'] ?? null, 'status' => $response->status()]);
            $this->fail(new RuntimeException('Evento rifiutato (HTTP '.$response->status().').'));

            return;
        }

        throw new RuntimeException('UnicoBPM ha risposto HTTP '.$response->status().'.');
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Evento per UnicoBPM non consegnato.', ['event' => $this->event['event'] ?? null, 'error' => class_basename($exception)]);
    }
}
