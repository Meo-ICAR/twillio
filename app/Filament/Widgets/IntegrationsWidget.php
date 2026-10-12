<?php

namespace App\Filament\Widgets;

use App\Services\Documents\AnthropicCredit;
use App\Services\SystemHealth;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class IntegrationsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Integrazioni';

    protected function getStats(): array
    {
        $health = app(SystemHealth::class);
        $wa = $health->whatsapp();
        $ai = $health->analysis();
        $purge = $health->lastPurge();
        $check = $health->aiCheck();
        $credit = app(AnthropicCredit::class)->isConfigured() ? app(AnthropicCredit::class)->estimate() : null;

        return [
            match (true) {
                $wa === null => Stat::make('WhatsApp', 'Nessun invio')->description('Non risultano messaggi inviati di recente')->color('gray'),
                $wa['ok'] => Stat::make('WhatsApp', 'Funziona')->description('Ultimo invio '.$wa['at']->diffForHumans())->color('success'),
                default => Stat::make('WhatsApp', 'Invio fallito')->description('Errore '.($wa['status'] ?? '?').' '.$wa['at']->diffForHumans().': controlla il token')->color('danger'),
            },
            $ai['active']
                ? Stat::make('Analisi AI', $ai['pending'].' in corso')
                    ->description($ai['failed_24h'].' non riuscite nelle ultime 24 ore'.($ai['oldest_pending'] ? ', la più vecchia da '.$ai['oldest_pending']->diffForHumans(null, true) : '').($check ? ' · Verifica '.$check['at']->diffForHumans().': '.$check['detail'] : ' · Credito: usa «Verifica AI» (l\'API non indica il residuo)'))
                    ->color($check && ! $check['ok'] ? 'danger' : ($ai['failed_24h'] ? 'warning' : 'success'))
                : Stat::make('Analisi AI', 'Non attiva')->description('Manca la chiave: i documenti si controllano a mano')->color('gray'),
            $credit === null
                ? Stat::make('Credito Anthropic', 'Non configurato')->description('Servono ANTHROPIC_ADMIN_API_KEY, ANTHROPIC_CREDIT_BALANCE e ANTHROPIC_CREDIT_AS_OF')->color('gray')
                : ($credit['ok']
                    ? Stat::make('Credito Anthropic (stima)', '$ '.number_format($credit['balance'], 2, ',', '.'))->description('$ '.number_format($credit['spent'], 2, ',', '.').' spesi dal '.Carbon::parse($credit['as_of'])->format('d/m/Y').' · aggiornato '.$credit['at']->diffForHumans())->color($credit['balance'] < 5 ? 'danger' : ($credit['balance'] < 20 ? 'warning' : 'success'))
                    : Stat::make('Credito Anthropic', 'Non disponibile')->description($credit['detail'])->color('danger')),
            Stat::make('Pulizia automatica', $purge ? $purge->diffForHumans() : 'Mai eseguita')
                ->description($purge ? 'Cancellazione delle pratiche scadute' : 'Il cron di schedule:run non risulta attivo')
                ->color($purge && $purge->gt(now()->subDays(2)) ? 'success' : 'danger'),
        ];
    }
}
