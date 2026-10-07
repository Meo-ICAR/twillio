<?php

namespace App\Filament\Resources\FlowChecks\Pages;

use App\Filament\Resources\FlowChecks\FlowCheckResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditFlowCheck extends EditRecord
{
    protected static string $resource = FlowCheckResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /** Un controllo usato da una domanda non si disattiva: il bot non saprebbe più verificare quella risposta. */
    protected function beforeSave(): void
    {
        $used = $this->record->usedBy();

        if (! ($this->data['is_active'] ?? true) && $used->isNotEmpty()) {
            Notification::make()->danger()->persistent()->title('Controllo in uso')
                ->body('Lo usano queste domande: '.$used->implode(', ').'. Toglilo prima da lì.')->send();

            $this->halt();
        }
    }
}
