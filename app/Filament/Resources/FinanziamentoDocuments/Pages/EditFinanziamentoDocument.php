<?php

namespace App\Filament\Resources\FinanziamentoDocuments\Pages;

use App\Filament\Resources\FinanziamentoDocuments\FinanziamentoDocumentResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFinanziamentoDocument extends EditRecord
{
    protected static string $resource = FinanziamentoDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
