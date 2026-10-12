<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Consegna a UnicoBPM l'esito di una chiamata API (solo metadati). Un trace perso non è un danno: pochi tentativi, nessun errore a chi chiama. */
class SendApiTrace implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 300];

    /** @param array<string,mixed> $call */
    public function __construct(public array $call) {}

    public function handle(): void
    {
        try {
            $response = Http::asJson()->timeout(5)->connectTimeout(3)
                ->withHeaders(['X-Api-Key' => (string) config('services.bpm.key'), 'X-Correlation-Id' => (string) ($this->call['correlation_id'] ?? '')])
                ->post(rtrim((string) config('services.bpm.url'), '/').'/api/api-logs', $this->call);
        } catch (Throwable) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 300);

            return;
        }

        if ($response->serverError()) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 300);
        }
    }
}
