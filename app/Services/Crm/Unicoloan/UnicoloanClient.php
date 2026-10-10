<?php

namespace App\Services\Crm\Unicoloan;

use App\Models\Company;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Client dell'API in ingresso di unicoloan (/api/agente/v1): token Bearer e firma HMAC-SHA256 di
 * «timestamp.METODO.percorso.sha256-del-contenuto» (il corpo JSON, o il file nei caricamenti). Vedi unicoloan docs/agent-api.md.
 */
class UnicoloanClient
{
    public function __construct(private readonly Company $company) {}

    public function isConfigured(): bool
    {
        return $this->url() !== '' && filled($this->config('token')) && filled($this->config('secret'));
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return ((array) $this->company->crm_config)[$key] ?? $default;
    }

    /** @param  array<string,mixed>|null  $body  @throws ConnectionException */
    public function json(string $method, string $path, ?array $body = null): Response
    {
        $content = $body === null ? '' : json_encode($body);

        $request = Http::timeout($this->timeout())->acceptJson()->withHeaders($this->headers($method, $path, hash('sha256', $content)));

        return $content === ''
            ? $request->send($method, $this->url().$path)
            : $request->withBody($content, 'application/json')->send($method, $this->url().$path);
    }

    /**
     * @param  array<string,string>  $fields
     *
     * @throws ConnectionException
     */
    public function upload(string $path, array $fields, string $filename, string $contents): Response
    {
        $hash = hash('sha256', $contents);

        return Http::timeout($this->timeout() * 4)->acceptJson()
            ->withHeaders($this->headers('POST', $path, $hash) + ['X-Content-Sha256' => $hash])
            ->attach('file', $contents, $filename)
            ->post($this->url().$path, $fields);
    }

    /** @return array<string,string> */
    private function headers(string $method, string $path, string $contentHash): array
    {
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', implode('.', [$timestamp, strtoupper($method), $path, $contentHash]), (string) $this->config('secret'));

        return ['Authorization' => 'Bearer '.$this->config('token'), 'X-Timestamp' => $timestamp, 'X-Signature' => $signature];
    }

    /** Indirizzo di base senza barra finale; solo https (http ammesso in sviluppo e test). */
    private function url(): string
    {
        $url = rtrim((string) $this->config('url', ''), '/');

        $allowed = str_starts_with($url, 'https://') || (str_starts_with($url, 'http://') && app()->environment(['local', 'testing']));

        return $allowed ? $url : '';
    }

    private function timeout(): int
    {
        return (int) $this->config('timeout', config('finanziamento.quote.timeout', 15));
    }
}
