<?php

namespace App\Filament\Resources\FlowChecks\Pages;

use App\Filament\Resources\FlowChecks\FlowCheckResource;
use App\Services\Checks\CheckRegistry;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListFlowChecks extends ListRecords
{
    protected static string $resource = FlowCheckResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('cercaNuovi')->label('Cerca nuovi controlli')->icon('heroicon-o-magnifying-glass')
                ->modalHeading('Cerca nuovi controlli')
                ->modalDescription('Cerca nella cartella dei controlli le classi che non sono ancora nell\'elenco e le aggiunge.')
                ->action(function () {
                    $added = app(CheckRegistry::class)->sync();

                    Notification::make()->success()
                        ->title($added === 0 ? 'Nessun controllo nuovo' : "Aggiunti {$added} controlli")
                        ->body($added === 0 ? 'Tutte le classi trovate sono già nell\'elenco.' : 'Ora si possono agganciare alle domande.')
                        ->send();
                }),
        ];
    }
}
