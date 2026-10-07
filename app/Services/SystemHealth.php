<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\LoanRequest;
use App\Models\PraticaDocument;
use App\Services\Documents\DocumentReader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

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

    /** @return array{per_stato: array<string,int>, in_scadenza: int, conversazioni_attive: int, conversazioni_ferme: int} */
    public function week(): array
    {
        $days = (int) config('privacy.retention_days');

        return [
            'per_stato' => LoanRequest::where('created_at', '>=', now()->subDays(7))->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all(),
            'in_scadenza' => LoanRequest::where('status', '!=', 'perfezionata')->where('created_at', '<', now()->subDays(max($days - 7, 0)))->count(),
            'conversazioni_attive' => Conversation::where('status', 'attiva')->count(),
            'conversazioni_ferme' => Conversation::where('status', 'attiva')->where('updated_at', '<', now()->subDay())->count(),
        ];
    }
}
