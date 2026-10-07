<?php

namespace App\Filament\Widgets;

use App\Services\SystemHealth;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

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

        return [
            match (true) {
                $wa === null => Stat::make('WhatsApp', 'Nessun invio')->description('Non risultano messaggi inviati di recente')->color('gray'),
                $wa['ok'] => Stat::make('WhatsApp', 'Funziona')->description('Ultimo invio '.$wa['at']->diffForHumans())->color('success'),
                default => Stat::make('WhatsApp', 'Invio fallito')->description('Errore '.($wa['status'] ?? '?').' '.$wa['at']->diffForHumans().': controlla il token')->color('danger'),
            },
            $ai['active']
                ? Stat::make('Analisi AI', $ai['pending'].' in corso')
                    ->description($ai['failed_24h'].' non riuscite nelle ultime 24 ore'.($ai['oldest_pending'] ? ', la più vecchia da '.$ai['oldest_pending']->diffForHumans(null, true) : ''))
                    ->color($ai['failed_24h'] ? 'warning' : 'success')
                : Stat::make('Analisi AI', 'Non attiva')->description('Manca la chiave: i documenti si controllano a mano')->color('gray'),
            Stat::make('Pulizia automatica', $purge ? $purge->diffForHumans() : 'Mai eseguita')
                ->description($purge ? 'Cancellazione delle pratiche scadute' : 'Il cron di schedule:run non risulta attivo')
                ->color($purge && $purge->gt(now()->subDays(2)) ? 'success' : 'danger'),
        ];
    }
}
