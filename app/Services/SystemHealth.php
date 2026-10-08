<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\LoanRequest;
use App\Models\PraticaDocument;
use App\Services\Documents\DocumentReader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Numeri della dashboard: cosa richiede un intervento e se le integrazioni funzionano. */
class SystemHealth
{
    /** Registra l'esito dell'ultimo invio WhatsApp (il token scaduto si vede da qui). */
    public static function recordWhatsApp(bool $ok, ?int $status): void
    {
        Cache::forever('health.whatsapp', ['ok' => $ok, 'status' => $status, 'at' => now()->toIso8601String()]);
        if ($ok) {
            Cache::forever('health.whatsapp_ok_at', now()->toIso8601String());
        }
    }

    public static function recordPurge(): void
    {
        Cache::forever('health.purge', now()->toIso8601String());
    }

    /** @return array{ok: bool, status: ?int, at: Carbon, last_ok: ?Carbon}|null */
    public function whatsapp(): ?array
    {
        $last = Cache::get('health.whatsapp');
        if (! $last) {
            return null;
        }
        $ok = Cache::get('health.whatsapp_ok_at');

        return ['ok' => $last['ok'], 'status' => $last['status'], 'at' => Carbon::parse($last['at']), 'last_ok' => $ok ? Carbon::parse($ok) : null];
    }

    /**
     * Verifica attiva del token WhatsApp: legge i dati del numero (come /test-whatsapp ma senza mandare messaggi).
     *
     * @return array{ok: bool, detail: string}
     */
    public function checkWhatsApp(): array
    {
        $id = config('services.whatsapp.phone_number_id');
        $token = config('services.whatsapp.token');
        if (! $id || ! $token) {
            self::recordWhatsApp(false, null);

            return ['ok' => false, 'detail' => 'Token o ID del numero non configurati.'];
        }

        try {
            $r = Http::withToken($token)->timeout(10)->get("https://graph.facebook.com/v20.0/{$id}", ['fields' => 'display_phone_number,verified_name']);
        } catch (\Throwable) {
            return ['ok' => false, 'detail' => 'Meta non raggiungibile.'];
        }

        self::recordWhatsApp($r->successful(), $r->status());

        return $r->successful()
            ? ['ok' => true, 'detail' => trim(($r->json('verified_name') ?? '').' '.($r->json('display_phone_number') ?? ''))]
            : ['ok' => false, 'detail' => (string) ($r->json('error.message') ?? 'Errore '.$r->status())];
    }

    /**
     * Verifica attiva dell'AI con una richiesta minima (1 token). L'API di Anthropic non espone il credito residuo:
     * si vede solo se la chiave vale e se il credito è esaurito.
     *
     * @return array{ok: bool, detail: string}
     */
    public function checkAi(): array
    {
        $key = config('services.anthropic.key');
        if (! $key) {
            return $this->recordAi(false, 'Chiave non configurata.');
        }

        try {
            $r = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->timeout(15)
                ->post('https://api.anthropic.com/v1/messages', ['model' => 'claude-haiku-4-5-20251001', 'max_tokens' => 1, 'messages' => [['role' => 'user', 'content' => 'ok']]]);
        } catch (\Throwable) {
            return $this->recordAi(false, 'Anthropic non raggiungibile.');
        }

        $message = (string) ($r->json('error.message') ?? '');

        return match (true) {
            $r->successful() => $this->recordAi(true, 'Chiave valida e credito disponibile.'),
            str_contains(strtolower($message), 'credit balance') => $this->recordAi(false, 'Credito esaurito: ricaricare.'),
            $r->status() === 401 => $this->recordAi(false, 'Chiave non valida.'),
            $r->status() === 429 => $this->recordAi(true, 'Chiave valida (limite di richieste raggiunto al momento).'),
            default => $this->recordAi(false, $message ?: 'Errore '.$r->status()),
        };
    }

    /** @return array{ok: bool, detail: string} */
    private function recordAi(bool $ok, string $detail): array
    {
        Cache::forever('health.ai', ['ok' => $ok, 'detail' => $detail, 'at' => now()->toIso8601String()]);

        return ['ok' => $ok, 'detail' => $detail];
    }

    /** @return array{ok: bool, detail: string, at: Carbon}|null */
    public function aiCheck(): ?array
    {
        $c = Cache::get('health.ai');

        return $c ? ['ok' => $c['ok'], 'detail' => $c['detail'], 'at' => Carbon::parse($c['at'])] : null;
    }

    public function lastPurge(): ?Carbon
    {
        $at = Cache::get('health.purge');

        return $at ? Carbon::parse($at) : null;
    }

    /** @return array{active: bool, pending: int, failed_24h: int, oldest_pending: ?Carbon} */
    public function analysis(): array
    {
        $pending = Attachment::whereIn('status', ['ricevuto', 'in_attesa_informativa'])
            ->where('received_at', '>=', now()->subMinutes(LoanRequest::ANALYSIS_WAIT_MINUTES));
        $oldest = (clone $pending)->min('received_at');

        return [
            'active' => app(DocumentReader::class)->enabled(),
            'pending' => $pending->count(),
            'failed_24h' => Attachment::whereIn('status', ['non_analizzato', 'non_leggibile'])->where('received_at', '>=', now()->subDay())->count(),
            'oldest_pending' => $oldest ? Carbon::parse($oldest) : null,
        ];
    }

    /** @return array{documenti_rifiutati: int, non_analizzati: int, informative_in_attesa: int, perfezionate_7g: int} */
    public function attention(): array
    {
        return [
            'documenti_rifiutati' => PraticaDocument::where('status', 'rejected')->where('code', '!=', 'informativa')->count(),
            'non_analizzati' => Attachment::whereIn('status', ['non_analizzato', 'non_leggibile'])->count(),
            'informative_in_attesa' => PraticaDocument::where('code', 'informativa')->whereIn('status', ['ricevuto', 'rejected'])->count(),
            'perfezionate_7g' => LoanRequest::where('status', 'perfezionata')->where('perfected_at', '>=', now()->subDays(7))->count(),
        ];
    }

    /** @return array{per_stato: array<string,int>, costo_ai_7g: float, letture_ai_7g: int, in_scadenza: int, conversazioni_attive: int, conversazioni_ferme: int} */
    public function week(): array
    {
        $days = (int) config('privacy.retention_days');

        return [
            'per_stato' => LoanRequest::where('created_at', '>=', now()->subDays(7))->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all(),
            'in_scadenza' => LoanRequest::where('status', '!=', 'perfezionata')->where('created_at', '<', now()->subDays(max($days - 7, 0)))->count(),
            'conversazioni_attive' => Conversation::where('status', 'attiva')->count(),
            'costo_ai_7g' => (float) Attachment::where('received_at', '>=', now()->subDays(7))->sum('ai_cost'),
            'letture_ai_7g' => Attachment::where('received_at', '>=', now()->subDays(7))->whereNotNull('ai_cost')->count(),
            'conversazioni_ferme' => Conversation::where('status', 'attiva')->where('updated_at', '<', now()->subDay())->count(),
        ];
    }
}
