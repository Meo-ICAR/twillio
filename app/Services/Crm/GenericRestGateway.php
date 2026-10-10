<?php

namespace App\Services\Crm;

use App\Models\Company;
use App\Models\LoanRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Driver per un CRM qualsiasi con un'API REST: l'indirizzo, l'autenticazione, il modello del messaggio e le regole di
 * esito stanno nella configurazione dell'azienda (companies.crm_config), senza scrivere codice.
 *
 * Chiavi di crm_config: url (obbligatoria), method (POST), format (json | form), auth (none | bearer | header | basic),
 * token, header_name, username, password, timeout, body_template (JSON con segnaposto {{cliente.cognome}}; vuoto = si
 * invia l'intero SubmissionPayload), success_codes (es. «200,201»; vuoto = qualsiasi 2xx), ok_path e ok_value (esito
 * nel corpo della risposta), id_path (dove leggere l'identificativo della pratica nel CRM).
 * Nei log solo il tipo di errore e il codice HTTP: mai dati personali.
 */
class GenericRestGateway implements CrmGateway
{
    public function __construct(private Company $company) {}

    public function submit(LoanRequest $loan, array $personal): int
    {
        $config = (array) $this->company->crm_config;
        $url = (string) ($config['url'] ?? '');

        if (! $this->urlIsAllowed($url)) {
            Log::warning('CRM generico: indirizzo mancante o non sicuro', ['loan' => $loan->code]);

            return 0;
        }

        $payload = SubmissionPayload::build($loan, $personal);
        $body = filled($config['body_template'] ?? null) ? $this->render((string) $config['body_template'], $payload) : $payload;

        try {
            $request = Http::timeout((int) ($config['timeout'] ?? config('finanziamento.quote.timeout', 15)));
            $request = $this->authenticate($request, $config);
            $response = $request->send(strtoupper((string) ($config['method'] ?? 'POST')), $url, $this->isForm($config) ? ['form_params' => $body] : ['json' => $body]);
        } catch (ConnectionException) {
            Log::error('CRM generico: servizio non raggiungibile', ['loan' => $loan->code]);

            return 0;
        }

        if (! $this->isSuccess($response->status(), $config)) {
            Log::warning('CRM generico: risposta HTTP', ['loan' => $loan->code, 'status' => $response->status()]);

            return $response->status() ?: 502;
        }

        $json = $response->json();

        if (filled($config['ok_path'] ?? null) && (string) Arr::get((array) $json, $config['ok_path']) !== (string) ($config['ok_value'] ?? '')) {
            Log::warning('CRM generico: esito negativo nel corpo della risposta', ['loan' => $loan->code]);

            return 422;
        }

        if (filled($config['id_path'] ?? null)) {
            $remote = Arr::get((array) $json, $config['id_path']);
            $loan->update(['crm_lead_id' => is_scalar($remote) && $remote !== '' ? (string) $remote : null]);
        }

        return 200;
    }

    private function urlIsAllowed(string $url): bool
    {
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        // https ovunque; http solo in sviluppo e test.
        return str_starts_with($url, 'https://') || (str_starts_with($url, 'http://') && app()->environment(['local', 'testing']));
    }

    private function isForm(array $config): bool
    {
        return ($config['format'] ?? 'json') === 'form';
    }

    /** @param  array<string,mixed>  $config */
    private function authenticate(PendingRequest $request, array $config): PendingRequest
    {
        return match ($config['auth'] ?? 'none') {
            'bearer' => $request->withToken((string) ($config['token'] ?? '')),
            'header' => $request->withHeaders([(string) ($config['header_name'] ?? 'X-Api-Key') => (string) ($config['token'] ?? '')]),
            'basic' => $request->withBasicAuth((string) ($config['username'] ?? ''), (string) ($config['password'] ?? '')),
            default => $request,
        };
    }

    /** @param  array<string,mixed>  $config */
    private function isSuccess(int $status, array $config): bool
    {
        if (filled($config['success_codes'] ?? null)) {
            return in_array($status, array_map('intval', array_filter(array_map('trim', explode(',', (string) $config['success_codes'])))), true);
        }

        return $status >= 200 && $status < 300;
    }

    /** Sostituisce i segnaposto {{percorso.nel.formato}} nel modello JSON: un segnaposto da solo conserva il tipo del valore. */
    private function render(string $template, array $payload): mixed
    {
        $decoded = json_decode($template, true);

        return $decoded === null ? $template : $this->walk($decoded, $payload);
    }

    private function walk(mixed $node, array $payload): mixed
    {
        if (is_array($node)) {
            return array_map(fn ($child) => $this->walk($child, $payload), $node);
        }

        if (! is_string($node)) {
            return $node;
        }

        if (preg_match('/^\{\{\s*([\w.]+)\s*\}\}$/', $node, $m)) {
            return Arr::get($payload, $m[1]);
        }

        return preg_replace_callback('/\{\{\s*([\w.]+)\s*\}\}/', function (array $m) use ($payload): string {
            $value = Arr::get($payload, $m[1]);

            return is_scalar($value) ? (string) $value : '';
        }, $node);
    }
}
