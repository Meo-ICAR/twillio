<?php

namespace App\Services\Bpm;

use App\Jobs\SendApiTrace;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Comunica a UnicoBPM l'esito delle chiamate API di unicoagent (Meta, Anthropic, CRM...), così compaiono nel suo trace «Log API».
 * Solo metadati: metodo, indirizzo senza query, codice, durata, tipo di errore e, per l'AI, i consumi. Mai intestazioni né contenuti.
 */
class ApiTrace
{
    public const ORIGIN = 'unicoagent';

    public static function isEnabled(): bool
    {
        return BpmEvents::isConfigured() && config('services.bpm.trace', true);
    }

    /**
     * @param  array{method: string, url: string, status_code?: ?int, duration_ms?: ?int, error?: ?string, ai?: ?array<string,mixed>, correlation_id?: ?string}  $call
     */
    public static function record(array $call): void
    {
        if (! self::isEnabled() || self::isBpm($call['url'])) {
            return;
        }

        $call['url'] = strtok($call['url'], '?') ?: $call['url'];
        SendApiTrace::dispatch($call + ['origin' => self::ORIGIN, 'correlation_id' => (string) Str::uuid()]);
    }

    /** Guzzle middleware per tutte le chiamate fatte con il client HTTP di Laravel. */
    public static function middleware(): callable
    {
        return fn (callable $handler): callable => function (RequestInterface $request, array $options) use ($handler) {
            $startedAt = microtime(true);
            $log = fn (?ResponseInterface $response, ?string $error) => self::record([
                'method' => $request->getMethod(),
                'url' => (string) $request->getUri(),
                'status_code' => $response?->getStatusCode(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'error' => $error,
            ]);

            try {
                $promise = $handler($request, $options);
            } catch (Throwable $e) {
                $log(null, class_basename($e));

                throw $e;
            }

            return $promise->then(
                function (ResponseInterface $response) use ($log) {
                    $log($response, null);

                    return $response;
                },
                function ($reason) use ($log) {
                    $log(null, $reason instanceof Throwable ? class_basename($reason) : 'errore');

                    throw $reason;
                },
            );
        };
    }

    private static function isBpm(string $url): bool
    {
        $bpm = parse_url((string) config('services.bpm.url'), PHP_URL_HOST);

        return $bpm !== null && $bpm === parse_url($url, PHP_URL_HOST);
    }
}
