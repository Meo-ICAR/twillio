<?php

namespace App\Filament\Resources\Flows\Pages;

use App\Filament\Resources\Flows\FlowResource;
use App\Services\Flows\FlowConfigExporter;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListFlows extends ListRecords
{
    protected static string $resource = FlowResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('grafo')->label('Grafo delle domande')->icon('heroicon-o-share')->color('gray')
                ->url('/grafo-domande')->openUrlInNewTab(),
            Action::make('esporta')->label('Esporta configurazione')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->tooltip('Scarica il file di configurazione ricreato dalle tabelle (percorsi di produzione e controlli attivi).')
                ->action(fn () => response()->streamDownload(
                    fn () => print (app(FlowConfigExporter::class)->export()),
                    'finanziamento.php',
                    ['Content-Type' => 'text/x-php'],
                )),
        ];
    }
}
