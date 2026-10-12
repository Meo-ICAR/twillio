<?php

namespace App\Services\Documents;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Credito residuo dell'account Anthropic, stimato: l'API non lo espone, quindi si parte dal saldo indicato a una data
 * (ANTHROPIC_CREDIT_BALANCE alla data ANTHROPIC_CREDIT_AS_OF) e si toglie la spesa reale letta dal cost report dell'Admin API.
 * Va riallineato a ogni ricarica. Serve una chiave amministrativa (ANTHROPIC_ADMIN_API_KEY, sk-ant-admin...).
 */
class AnthropicCredit
{
    private const CACHE_KEY = 'health.ai_credit';

    public function isConfigured(): bool
    {
        return filled(config('services.anthropic.admin_key')) && config('services.anthropic.credit_balance') !== null && filled(config('services.anthropic.credit_as_of'));
    }

    /**
     * @return array{ok: bool, detail: string, balance: ?float, spent: ?float, as_of: ?string, at: ?Carbon}
     */
    public function estimate(bool $refresh = false): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'detail' => 'Servono ANTHROPIC_ADMIN_API_KEY, ANTHROPIC_CREDIT_BALANCE e ANTHROPIC_CREDIT_AS_OF.', 'balance' => null, 'spent' => null, 'as_of' => null, 'at' => null];
        }

        if ($refresh) {
            Cache::forget(self::CACHE_KEY);
        }

        return Cache::remember(self::CACHE_KEY, now()->addMinutes(10), fn () => $this->fetch());
    }

    /** @return array{ok: bool, detail: string, balance: ?float, spent: ?float, as_of: ?string, at: ?Carbon} */
    private function fetch(): array
    {
        $asOf = Carbon::parse((string) config('services.anthropic.credit_as_of'), 'UTC')->startOfDay();
        $spentCents = 0.0;
        $page = null;

        try {
            do {
                $r = Http::withHeaders(['x-api-key' => (string) config('services.anthropic.admin_key'), 'anthropic-version' => '2023-06-01'])->timeout(15)
                    ->get('https://api.anthropic.com/v1/organizations/cost_report', array_filter([
                        'starting_at' => $asOf->toIso8601ZuluString(), 'ending_at' => now()->utc()->addDay()->startOfDay()->toIso8601ZuluString(), 'limit' => 31, 'page' => $page,
                    ]));

                if (! $r->successful()) {
                    return $this->failure($r->status() === 401 || $r->status() === 403 ? 'Chiave amministrativa non valida o senza permessi.' : (string) ($r->json('error.message') ?? 'Errore '.$r->status()));
                }

                foreach ((array) $r->json('data', []) as $bucket) {
                    foreach ((array) ($bucket['results'] ?? []) as $result) {
                        $spentCents += (float) ($result['amount'] ?? 0);
                    }
                }

                $page = $r->json('has_more') ? $r->json('next_page') : null;
            } while ($page);
        } catch (Throwable) {
            return $this->failure('Anthropic non raggiungibile.');
        }

        $spent = round($spentCents / 100, 2);
        $balance = round((float) config('services.anthropic.credit_balance') - $spent, 2);

        return ['ok' => true, 'detail' => "Stima: \${$balance} (saldo di partenza meno \${$spent} spesi dal {$asOf->format('d/m/Y')})", 'balance' => $balance, 'spent' => $spent, 'as_of' => $asOf->toDateString(), 'at' => now()];
    }

    /** @return array{ok: bool, detail: string, balance: null, spent: null, as_of: null, at: Carbon} */
    private function failure(string $detail): array
    {
        return ['ok' => false, 'detail' => $detail, 'balance' => null, 'spent' => null, 'as_of' => null, 'at' => now()];
    }
}
