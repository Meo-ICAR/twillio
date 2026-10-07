<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\LoanRequests\LoanRequestResource;
use App\Services\SystemHealth;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AttentionWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $heading = 'Da fare adesso';

    protected function getStats(): array
    {
        $a = app(SystemHealth::class)->attention();
        $url = LoanRequestResource::getUrl('index');

        return [
            Stat::make('Documenti rifiutati', $a['documenti_rifiutati'])->description('Da correggere dall\'agente o da rivedere')->color($a['documenti_rifiutati'] ? 'danger' : 'success')->url($url),
            Stat::make('Non letti dall\'AI', $a['non_analizzati'])->description('Da controllare a mano')->color($a['non_analizzati'] ? 'warning' : 'success')->url($url),
            Stat::make('Informative da verificare', $a['informative_in_attesa'])->description('Finché non sono a posto i documenti restano fermi')->color($a['informative_in_attesa'] ? 'warning' : 'success')->url($url),
            Stat::make('Perfezionate (7 giorni)', $a['perfezionate_7g'])->description('Da prendere in carico nel CRM')->color('info')->url($url),
        ];
    }
}
