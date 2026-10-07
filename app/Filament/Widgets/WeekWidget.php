<?php

namespace App\Filament\Widgets;

use App\Models\LoanRequest;
use App\Services\SystemHealth;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class WeekWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

    protected ?string $heading = 'Ultimi 7 giorni';

    protected function getStats(): array
    {
        $w = app(SystemHealth::class)->week();
        $states = collect(LoanRequest::STATUSES)->map(fn ($label, $key) => ($w['per_stato'][$key] ?? 0).' '.strtolower($label))->implode(' · ');

        return [
            Stat::make('Pratiche nuove', array_sum($w['per_stato']))->description($states),
            Stat::make('Prossime alla cancellazione', $w['in_scadenza'])->description('Non perfezionate, entro 7 giorni dal termine di conservazione')->color($w['in_scadenza'] ? 'warning' : 'success'),
            Stat::make('Conversazioni attive', $w['conversazioni_attive'])->description($w['conversazioni_ferme'].' ferme da più di 24 ore'),
        ];
    }
}
